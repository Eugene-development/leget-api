<?php

namespace Tests\Feature\Payment;

use App\Console\Commands\CancelInvoice;
use App\Console\Commands\PayInvoice;
use App\GraphQL\Mutations\CancelLicense;
use App\GraphQL\Mutations\CreateLicense;
use App\Models\Invoice;
use App\Models\License;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BillingService;
use App\Services\InvoicePaymentService;
use App\Services\PaymentService;
use App\Services\TemplateService;
use App\Services\WalletOpeningBalance;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class BillingSafetyTest extends OnlineTopUpTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('lighthouse.schema_cache.enable', false);
        Carbon::setTestNow(Carbon::parse('2026-09-21 07:00:00', 'Europe/Moscow'));
        Schema::create('licenses', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id');
            $t->string('domain');
            $t->string('name')->nullable();
            $t->integer('template_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('status')->default('active');
            $t->decimal('daily_price', 10, 2)->default(150);
            $t->timestamp('billing_started_at')->nullable();
            $t->timestamps();
        });
        Schema::create('pages', function (Blueprint $t) {
            $t->id();
            $t->string('license_id');
            $t->string('slug');
            $t->timestamps();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->string('domain');
            $t->string('status');
            $t->boolean('is_active');
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->foreignId('wallet_id');
            $t->string('number')->unique();
            $t->decimal('amount', 15, 2);
            $t->string('status');
            $t->string('company_name');
            $t->string('inn')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path('../leget-db/database/migrations/2026_09_21_210000_harden_subscription_billing.php');
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'balance' => '1000.00']);
        $license = License::create(['user_id' => $user->id, 'name' => 'Audit', 'domain' => 'audit.test', 'template_id' => 1,
            'status' => 'active', 'is_active' => true, 'daily_price' => '150.00',
            'billing_started_at' => Carbon::parse('2026-09-21 06:30:00', 'Europe/Moscow'), 'next_billing_date' => '2026-09-21']);

        return [$user, $wallet, $license];
    }

    private function context(User $user): GraphQLContext
    {
        $context = Mockery::mock(GraphQLContext::class);
        $context->shouldReceive('user')->andReturn($user);

        return $context;
    }

    private function info(): ResolveInfo
    {
        return (new \ReflectionClass(ResolveInfo::class))->newInstanceWithoutConstructor();
    }

    private function invoice(User $user, Wallet $wallet): Invoice
    {
        return Invoice::create(['user_id' => $user->id, 'wallet_id' => $wallet->id, 'number' => '202609-00001',
            'amount' => '500.00', 'status' => 'pending', 'company_name' => 'Audit']);
    }

    private function command(string $class, callable $confirm)
    {
        $command = Mockery::mock($class)->makePartial();
        $command->shouldReceive('argument')->with('number')->andReturn('202609-00001');
        $command->shouldReceive('info')->zeroOrMoreTimes();
        $command->shouldReceive('warn')->zeroOrMoreTimes();
        $command->shouldReceive('error')->zeroOrMoreTimes();
        $command->shouldReceive('confirm')->once()->andReturnUsing($confirm);

        return $command;
    }

    public function test_daily_retry_and_catch_up_use_one_entry_per_moscow_day(): void
    {
        [$user, $wallet, $license] = $this->fixture();
        $this->assertSame(1, (new BillingService)->processDailyDebits()['charged']);
        $this->assertSame(0, (new BillingService)->processDailyDebits()['charged']);
        $this->assertSame('850.00', $wallet->fresh()->balance);
        Carbon::setTestNow(Carbon::parse('2026-09-24 07:00:00', 'Europe/Moscow'));
        $this->assertSame(3, (new BillingService)->processDailyDebits()['charged']);
        $this->assertSame('400.00', $wallet->fresh()->balance);
        $this->assertSame(['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24'], Transaction::orderBy('billing_date')->pluck('billing_date')->all());
        $this->assertSame('2026-09-25', $license->fresh()->next_billing_date);
    }

    public function test_trial_waits_for_first_scheduled_time_after_full_72_hours(): void
    {
        [$user, $wallet, $license] = $this->fixture();
        $start = Carbon::parse('2026-09-21 18:00:00', 'Europe/Moscow');
        $license->update(['billing_started_at' => $start, 'next_billing_date' => BillingService::firstBillingDate($start)]);
        $this->assertSame('2026-09-22', $license->fresh()->next_billing_date);
        Carbon::setTestNow(Carbon::parse('2026-09-22 06:29:59', 'Europe/Moscow'));
        $this->assertSame(0, (new BillingService)->processDailyDebits()['charged']);
        Carbon::setTestNow(Carbon::parse('2026-09-22 06:30:00', 'Europe/Moscow'));
        $this->assertSame(1, (new BillingService)->processDailyDebits()['charged']);
    }

    public function test_cancel_before_scheduled_billing_prevents_later_debits_and_is_retryable(): void
    {
        [$user, $wallet, $license] = $this->fixture();
        Carbon::setTestNow(Carbon::parse('2026-09-21 06:00:00', 'Europe/Moscow'));
        $cancel = new CancelLicense;
        $cancel(null, ['id' => $license->id], $this->context($user), $this->info());
        $cancel(null, ['id' => $license->id], $this->context($user), $this->info());
        Carbon::setTestNow(Carbon::parse('2026-09-22 07:00:00', 'Europe/Moscow'));
        $this->assertSame(0, (new BillingService)->processDailyDebits()['charged']);
        $this->assertSame('1000.00', $wallet->fresh()->balance);
    }

    public function test_cancellation_after_selection_is_rechecked_under_lock(): void
    {
        [$user, $wallet, $license] = $this->fixture();
        $changed = false;
        License::retrieved(function ($model) use (&$changed, $license) {
            if (! $changed && $model->id === $license->id) {
                $changed = true;
                DB::table('licenses')->where('id', $license->id)->update(['status' => 'cancelled', 'is_active' => false]);
            }
        });
        try {
            $this->assertSame(0, (new BillingService)->processDailyDebits()['charged']);
            $this->assertSame('1000.00', $wallet->fresh()->balance);
        } finally {
            License::flushEventListeners();
        }
    }

    public function test_failed_site_creation_rolls_back_license_and_pages(): void
    {
        [$user] = $this->fixture();
        License::query()->delete();
        $templates = Mockery::mock(TemplateService::class);
        $templates->shouldReceive('getTemplate')->with(1)->andReturn(['pages' => ['/' => []]]);
        $templates->shouldReceive('seedDefaultComponents')->once()->andThrow(new \RuntimeException('setup failed'));
        try {
            (new CreateLicense($templates))(null, ['template_id' => 1], $this->context($user), $this->info());
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('setup failed', $e->getMessage());
        }
        $this->assertSame(0, License::count());
        $this->assertSame(0, DB::table('pages')->count());
    }

    public function test_create_license_retry_returns_same_subscription(): void
    {
        $user = User::factory()->create();
        $templates = Mockery::mock(TemplateService::class);
        $templates->shouldReceive('getTemplate')->andReturn(['pages' => []]);
        $create = new CreateLicense($templates);
        $args = ['template_id' => 1, 'creationKey' => (string) Str::uuid()];
        $first = $create(null, $args, $this->context($user), $this->info());
        $second = $create(null, $args, $this->context($user), $this->info());
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, License::count());
        $this->assertSame(1, Wallet::where('user_id', $user->id)->count());
    }

    public function test_overlapping_invoice_confirmations_only_credit_once(): void
    {
        [$user, $wallet] = $this->fixture();
        $invoice = $this->invoice($user, $wallet);
        $second = $this->command(PayInvoice::class, fn () => true);
        $first = $this->command(PayInvoice::class, function () use ($second) {
            $second->handle();

            return true;
        });
        $this->assertSame(0, $first->handle());
        $this->assertSame('1500.00', $wallet->fresh()->balance);
        $this->assertSame(1, Transaction::where('type', 'deposit')->count());
        $this->assertNotNull($invoice->fresh()->transaction_id);
    }

    public function test_cancel_confirmation_rechecks_paid_invoice(): void
    {
        [$user, $wallet] = $this->fixture();
        $invoice = $this->invoice($user, $wallet);
        $pay = $this->command(PayInvoice::class, fn () => true);
        $cancel = $this->command(CancelInvoice::class, function () use ($pay) {
            $pay->handle();

            return true;
        });
        $this->assertSame(1, $cancel->handle());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('1500.00', $wallet->fresh()->balance);
    }

    public function test_canceled_invoice_cannot_be_paid_from_old_confirmation(): void
    {
        [$user, $wallet] = $this->fixture();
        $invoice = $this->invoice($user, $wallet);
        $pay = $this->command(PayInvoice::class, function () use ($invoice) {
            app(InvoicePaymentService::class)->cancel($invoice->id);

            return true;
        });
        $this->assertSame(1, $pay->handle());
        $this->assertSame('1000.00', $wallet->fresh()->balance);
    }

    private function payment(User $user, Wallet $wallet): Payment
    {
        return Payment::create(['user_id' => $user->id, 'wallet_id' => $wallet->id, 'provider' => 'yookassa',
            'provider_payment_id' => 'audit-payment', 'idempotence_key' => (string) Str::uuid(), 'amount' => '500.00', 'currency' => 'RUB', 'status' => 'pending']);
    }

    private function provider(array $overrides = []): array
    {
        return array_replace(['id' => 'audit-payment', 'status' => 'succeeded', 'test' => false, 'paid' => true,
            'amount' => ['value' => '500.00', 'currency' => 'RUB']], $overrides);
    }

    public function test_test_payment_is_recorded_without_changing_balance(): void
    {
        [$user, $wallet] = $this->fixture();
        $payment = $this->payment($user, $wallet);
        app(PaymentService::class)->applyProviderState($payment, $this->provider(['test' => true]));
        $this->assertSame('1000.00', $wallet->fresh()->balance);
        $this->assertSame('test', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->transaction_id);
    }

    public function test_delayed_response_cannot_overwrite_credited_status(): void
    {
        [$user, $wallet] = $this->fixture();
        $payment = $this->payment($user, $wallet);
        $stale = $payment->fresh();
        app(PaymentService::class)->applyProviderState($payment, $this->provider());
        app(PaymentService::class)->applyProviderState($stale, $this->provider(['status' => 'waiting_for_capture']));
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('1500.00', $wallet->fresh()->balance);
        $this->assertSame(1, Transaction::count());
    }

    public function test_mismatched_payment_amount_does_not_credit_or_mark_succeeded(): void
    {
        [$user, $wallet] = $this->fixture();
        $payment = $this->payment($user, $wallet);
        try {
            app(PaymentService::class)->applyProviderState($payment, $this->provider(['amount' => ['value' => '501.00', 'currency' => 'RUB']]));
            $this->fail('Expected mismatch rejection');
        } catch (\RuntimeException) {
        }
        $this->assertSame('1000.00', $wallet->fresh()->balance);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, Transaction::count());
    }

    public function test_wallet_is_created_once_for_existing_account(): void
    {
        $user = User::factory()->create();
        $first = Wallet::forUser($user->id);
        $second = Wallet::forUser($user->id);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('0.00', $first->fresh()->balance);
    }

    public function test_migration_does_not_rebill_today_or_historical_gaps(): void
    {
        [$user, $wallet, $license] = $this->fixture();
        Transaction::create(['wallet_id' => $wallet->id, 'license_id' => $license->id, 'type' => 'withdraw', 'amount' => '150.00']);
        $this->migration()->down();
        $this->migration()->up();
        $this->assertSame('2026-09-22', $license->fresh()->next_billing_date);
        $this->assertSame('1000.00', $wallet->fresh()->balance);
        $this->assertSame(0, (new BillingService)->processDailyDebits()['charged']);
    }

    public function test_graphql_filters_before_pagination_and_scopes_wallet(): void
    {
        [$user, $wallet] = $this->fixture();
        for ($i = 0; $i < 25; $i++) {
            Transaction::create(['wallet_id' => $wallet->id, 'type' => 'withdraw', 'amount' => '150.00']);
        }
        DB::table('transactions')->insert(['wallet_id' => $wallet->id, 'type' => 'deposit', 'amount' => '500.00', 'created_at' => '2026-07-15 12:00:00']);
        $other = User::factory()->create();
        $otherWallet = Wallet::forUser($other->id);
        Transaction::create(['wallet_id' => $otherWallet->id, 'type' => 'deposit', 'amount' => '999.00']);
        $response = $this->actingAs($user, 'api')->postJson('/graphql', ['query' => '{ myWallet { transactions(first: 1, type: "deposit", dateFrom: "2026-07-01", dateTo: "2026-07-31") { data { amount type } paginatorInfo { total } } } }']);
        $response->assertJsonMissingPath('errors')->assertJsonPath('data.myWallet.transactions.paginatorInfo.total', 1)
            ->assertJsonPath('data.myWallet.transactions.data.0.amount', '500.00');
    }

    public function test_billing_failure_rolls_back_money_and_returns_nonzero_exit_code(): void
    {
        [$user, $wallet, $license] = $this->fixture();
        $license->update(['daily_price' => '-150.00']);
        $this->artisan('app:daily-billing')->assertExitCode(1);
        $this->assertSame('1000.00', $wallet->fresh()->balance);
        $this->assertSame(0, Transaction::count());
    }

    public function test_confirmed_opening_balance_reconciles_history_without_changing_money(): void
    {
        [$user, $wallet] = $this->fixture();
        $service = new WalletOpeningBalance;
        $service->record($wallet->id, '1000.00', 'confirmed test balance');
        $this->assertSame(0, Transaction::count());
        $service->record($wallet->id, '1000.00', 'confirmed test balance', true);
        $service->record($wallet->id, '1000.00', 'confirmed test balance', true);
        $this->assertSame('1000.00', $wallet->fresh()->balance);
        $this->assertSame(1, Transaction::count());
        $this->assertSame('1000.00', Transaction::first()->amount);
    }

    public function test_rate_change_settles_due_days_at_previous_rate(): void
    {
        [$user, $wallet, $license] = $this->fixture();
        Carbon::setTestNow(Carbon::parse('2026-09-23 07:00:00', 'Europe/Moscow'));
        config()->set('waas.template_prices.1', '200.00');
        $this->artisan('app:resync-license-prices', ['--apply' => true])->assertExitCode(0);
        $this->assertSame('550.00', $wallet->fresh()->balance);
        $this->assertSame(['150.00'], Transaction::get()->pluck('amount')->unique()->values()->all());
        Carbon::setTestNow(Carbon::parse('2026-09-24 07:00:00', 'Europe/Moscow'));
        (new BillingService)->processDailyDebits();
        $this->assertSame('350.00', $wallet->fresh()->balance);
    }

    public function test_date_filter_uses_moscow_midnight_and_returns_offset(): void
    {
        [$user, $wallet] = $this->fixture();
        DB::table('transactions')->insert([
            ['wallet_id' => $wallet->id, 'type' => 'deposit', 'amount' => '10.00', 'created_at' => '2026-07-31 23:59:59'],
            ['wallet_id' => $wallet->id, 'type' => 'deposit', 'amount' => '20.00', 'created_at' => '2026-08-01 00:00:00'],
        ]);
        $this->actingAs($user, 'api')->postJson('/graphql', ['query' => '{ myWallet { transactions(first: 20, dateFrom: "2026-07-31", dateTo: "2026-07-31") { data { amount occurredAt } paginatorInfo { total } } } }'])
            ->assertJsonMissingPath('errors')->assertJsonPath('data.myWallet.transactions.paginatorInfo.total', 1)
            ->assertJsonPath('data.myWallet.transactions.data.0.occurredAt', '2026-07-31T23:59:59+03:00');
    }
}
