<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaymentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Онлайн-пополнение баланса через ЮKassa.
 *
 * Проверяется главное свойство: зачисление на баланс происходит ровно один
 * раз, сколько бы уведомлений и синхронизаций ни пришло по одному платежу.
 *
 * Таблицы wallets / transactions / payments создаются здесь вручную —
 * миграции проекта живут в отдельном сервисе leget-db.
 */
class OnlineTopUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('wallets')) {
            Schema::create('wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->decimal('balance', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('wallet_id')->constrained()->onDelete('cascade');
                $table->string('license_id', 26)->nullable();
                $table->decimal('amount', 15, 2);
                $table->string('type');
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('wallet_id')->constrained()->onDelete('cascade');
                $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provider', 30)->default('yookassa');
                $table->string('provider_payment_id', 64)->nullable()->unique();
                $table->uuid('idempotence_key')->unique();
                $table->decimal('amount', 15, 2);
                $table->string('currency', 3)->default('RUB');
                $table->string('status')->default('pending');
                $table->string('cancellation_reason')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->json('provider_payload')->nullable();
                $table->timestamps();
            });
        }

        config()->set('billing.yookassa.shop_id', '1386671');
        config()->set('billing.yookassa.secret_key', 'test_secret');
        config()->set('billing.yookassa.return_url', 'https://leget.ru/lk/balance');
    }

    public function test_successful_payment_credits_balance_exactly_once(): void
    {
        [$user, $wallet] = $this->userWithWallet('100.00');
        $payment = $this->pendingPayment($user, $wallet, '500.00');

        Http::fake([
            '*/payments/*' => Http::response($this->providerPayment('succeeded', '500.00')),
        ]);

        $service = app(PaymentService::class);

        // Первая синхронизация: деньги зачислены
        $service->sync($payment);
        // Повторные (webhook + возврат пользователя) не должны ничего добавить
        $service->sync($payment->fresh());
        $service->sync($payment->fresh());

        $this->assertSame('600.00', $wallet->fresh()->balance);
        $this->assertSame(1, Transaction::where('wallet_id', $wallet->id)->count());

        $payment->refresh();
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->status);
        $this->assertNotNull($payment->transaction_id);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_canceled_payment_does_not_credit_balance(): void
    {
        [$user, $wallet] = $this->userWithWallet('100.00');
        $payment = $this->pendingPayment($user, $wallet, '500.00');

        Http::fake([
            '*/payments/*' => Http::response([
                'id'                   => $payment->provider_payment_id,
                'status'               => 'canceled',
                'paid'                 => false,
                'amount'               => ['value' => '500.00', 'currency' => 'RUB'],
                'cancellation_details' => ['reason' => 'payment_canceled_by_merchant'],
            ]),
        ]);

        app(PaymentService::class)->sync($payment);

        $this->assertSame('100.00', $wallet->fresh()->balance);
        $this->assertSame(0, Transaction::where('wallet_id', $wallet->id)->count());

        $payment->refresh();
        $this->assertSame(Payment::STATUS_CANCELED, $payment->status);
        $this->assertNull($payment->transaction_id);
        $this->assertSame('payment_canceled_by_merchant', $payment->cancellation_reason);
    }

    public function test_pending_payment_keeps_balance_untouched(): void
    {
        [$user, $wallet] = $this->userWithWallet('100.00');
        $payment = $this->pendingPayment($user, $wallet, '500.00');

        Http::fake([
            '*/payments/*' => Http::response($this->providerPayment('pending', '500.00')),
        ]);

        app(PaymentService::class)->sync($payment);

        $this->assertSame('100.00', $wallet->fresh()->balance);
        $this->assertNull($payment->fresh()->transaction_id);
    }

    public function test_webhook_rejects_untrusted_ip(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->postJson('/webhooks/yookassa', [
                'type'   => 'notification',
                'event'  => 'payment.succeeded',
                'object' => ['id' => 'whatever', 'status' => 'succeeded'],
            ]);

        $response->assertStatus(403);
    }

    public function test_webhook_from_trusted_ip_credits_balance(): void
    {
        [$user, $wallet] = $this->userWithWallet('0.00');
        $payment = $this->pendingPayment($user, $wallet, '250.50');

        Http::fake([
            '*/payments/*' => Http::response($this->providerPayment('succeeded', '250.50')),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '185.71.76.5'])
            ->postJson('/webhooks/yookassa', [
                'type'   => 'notification',
                'event'  => 'payment.succeeded',
                'object' => ['id' => $payment->provider_payment_id, 'status' => 'succeeded'],
            ]);

        $response->assertOk();
        $this->assertSame('250.50', $wallet->fresh()->balance);
        $this->assertSame(1, Transaction::where('wallet_id', $wallet->id)->count());
    }

    public function test_webhook_ignores_unknown_payment(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '185.71.76.5'])
            ->postJson('/webhooks/yookassa', [
                'type'   => 'notification',
                'event'  => 'payment.succeeded',
                'object' => ['id' => 'unknown-payment-id', 'status' => 'succeeded'],
            ]);

        $response->assertOk();
        $this->assertSame(0, Transaction::count());
    }

    /**
     * @return array{0: User, 1: Wallet}
     */
    private function userWithWallet(string $balance): array
    {
        $user   = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'balance' => $balance]);

        return [$user, $wallet];
    }

    private function pendingPayment(User $user, Wallet $wallet, string $amount): Payment
    {
        return Payment::create([
            'user_id'             => $user->id,
            'wallet_id'           => $wallet->id,
            'provider'            => 'yookassa',
            'provider_payment_id' => 'yk-' . Str::uuid(),
            'idempotence_key'     => (string) Str::uuid(),
            'amount'              => $amount,
            'currency'            => 'RUB',
            'status'              => Payment::STATUS_PENDING,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function providerPayment(string $status, string $amount): array
    {
        return [
            'id'     => 'yk-payment',
            'status' => $status,
            'paid'   => $status === 'succeeded',
            'amount' => ['value' => $amount, 'currency' => 'RUB'],
        ];
    }
}
