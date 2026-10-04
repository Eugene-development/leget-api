<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\Crm\CrmDefaults;
use App\Services\Growth\AutomationService;
use App\Services\Growth\OfflineConversionService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class GrowthInsightsTest extends TestCase
{
    private User $owner;

    private User $manager;

    private string $site;

    private string $other;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('licenses', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('domain');
            $t->unsignedBigInteger('user_id');
        });
        foreach (['2026_05_21_000001_create_service_requests_table.php', '2026_08_08_000001_create_conversions_table.php', '2026_09_15_120000_unify_form_submissions.php', '2026_09_23_180000_create_crm_tables.php', '2026_08_30_100006_create_user_notifications_table.php', '2026_10_03_100000_growth_insights_and_automation.php'] as $file) {
            (require base_path('../leget-db/database/migrations/'.$file))->up();
        }
        require base_path('routes/growth-insights.php');
        $this->owner = User::create(['name' => 'Owner', 'email' => 'growth-owner@test.local', 'password' => 'secret']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'growth-manager@test.local', 'password' => 'secret']);
        $this->manager->forceFill(['role' => Role::Manager])->save();
        $this->site = (string) Str::ulid();
        $this->other = (string) Str::ulid();
        DB::table('licenses')->insert([['id' => $this->site, 'domain' => 'growth.test', 'user_id' => $this->owner->id], ['id' => $this->other, 'domain' => 'other.test', 'user_id' => $this->owner->id]]);
        foreach ([$this->site, $this->other] as $site) {
            app(CrmDefaults::class)->seed($site);
        }
        DB::table('crm_memberships')->insert(['id' => (string) Str::ulid(), 'license_id' => $this->site, 'user_id' => $this->manager->id, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->owner, 'api');
        Http::preventStrayRequests();
    }

    public function test_cohort_deduplicates_deals_and_payments_and_isolates_tenants(): void
    {
        $deal = $this->deal($this->site, ['signed_at' => now()->toDateString(), 'contract_number' => '100', 'contract_date' => now()->toDateString(), 'amount' => 100]);
        $this->request($this->site, $deal, ['source_url' => 'https://growth.test/?utm_source=yandex&utm_campaign=kitchen']);
        $this->request($this->site, $deal, ['source_url' => 'https://growth.test/?utm_source=yandex&utm_campaign=kitchen']);
        $this->payment($this->site, $deal, 'payment', 120);
        $this->payment($this->site, $deal, 'refund', 20);
        $foreign = $this->deal($this->other, ['amount' => 900]);
        $this->request($this->other, $foreign);
        $r = $this->getJson($this->path('insights').'?from='.now()->subDay()->toDateString().'&to='.now()->toDateString())->assertOk();
        $r->assertJsonPath('totals.requests', 2)->assertJsonPath('totals.deals', 1)->assertJsonPath('totals.signed', 1)->assertJsonPath('totals.paid_orders', 1);
        $this->assertEquals(100, $r->json('totals.net_paid'));
        $r->assertJsonPath('sources.0.source', 'yandex');
        $this->actingAs($this->manager, 'api')->getJson($this->path('insights', $this->other))->assertForbidden();
        $this->getJson($this->path('insights').'?from=2026-10-03&to=2026-10-01')->assertUnprocessable();
    }

    public function test_rules_are_owner_only_and_task_and_notification_commit_once(): void
    {
        $id = $this->request($this->site, null, ['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)]);
        $body = ['name' => 'Не разобрана заявка', 'trigger' => 'unassigned', 'delay_minutes' => 60, 'due_minutes' => 120, 'task_title' => 'Назначить ответственного', 'enabled' => true, 'request_key' => (string) Str::uuid()];
        $a = $this->postJson($this->path('automation'), $body)->assertOk();
        $this->postJson($this->path('automation'), $body)->assertOk()->assertJsonPath('item.id', $a->json('item.id'));
        $service = app(AutomationService::class);
        $this->assertSame(1, $service->run($this->site));
        $this->assertSame(0, $service->run($this->site));
        $this->assertDatabaseCount('crm_tasks', 1);
        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseHas('growth_automation_runs', ['entity_id' => $id]);
        $this->actingAs($this->manager, 'api')->postJson($this->path('automation'), $body)->assertForbidden();
        $this->getJson($this->path('automation', $this->other))->assertForbidden();
    }

    public function test_first_request_outside_range_is_not_reattributed_and_manual_deals_are_separate(): void
    {
        $deal = $this->deal($this->site);
        $this->request($this->site, $deal, ['created_at' => now()->subMonth(), 'source_url' => 'https://growth.test/?utm_source=yandex']);
        $this->request($this->site, $deal, ['source_url' => 'https://growth.test/?utm_source=email']);
        $manual = $this->deal($this->site);
        $this->payment($this->site, $deal, 'payment', 900);
        $this->payment($this->site, $manual, 'payment', 100);
        $r = $this->getJson($this->path('insights').'?from='.now()->toDateString().'&to='.now()->toDateString())->assertOk();
        $r->assertJsonPath('totals.requests', 1)->assertJsonPath('totals.deals', 1);
        $this->assertEquals(100, $r->json('totals.net_paid'));
        $r->assertJsonPath('sources.0.source', 'manual');
    }

    public function test_metrika_settings_encrypt_secret_and_validate_optimistic_version(): void
    {
        $body = ['counter_id' => '999', 'oauth_token' => 'private-oauth-token', 'signed_goal' => 'crm_signed', 'paid_goal' => null, 'enabled' => true, 'request_key' => (string) Str::uuid()];
        $this->postJson($this->path('metrika'), $body)->assertOk();
        $this->postJson($this->path('metrika'), $body)->assertOk();
        $stored = DB::table('growth_metrika_settings')->where('license_id', $this->site)->first();
        $this->assertSame('private-oauth-token', Crypt::decryptString($stored->oauth_token));
        $this->assertStringNotContainsString('private-oauth-token', json_encode(DB::table('crm_operations')->get()));
        $this->getJson($this->path('metrika'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $body['request_key'] = (string) Str::uuid();
        $body['version'] = 99;
        $this->postJson($this->path('metrika'), $body)->assertConflict();
        $body['request_key'] = (string) Str::uuid();
        $body['version'] = 1;
        $body['oauth_token'] = null;
        $this->postJson($this->path('metrika'), $body)->assertOk();
        $this->assertSame('private-oauth-token', Crypt::decryptString(DB::table('growth_metrika_settings')->where('license_id', $this->site)->value('oauth_token')));
    }

    public function test_automation_failure_rolls_back_task_and_run_marker(): void
    {
        $this->request($this->site, null, ['created_at' => now()->subHours(2)]);
        $this->postJson($this->path('automation'), ['name' => 'Разобрать', 'trigger' => 'unassigned', 'delay_minutes' => 60, 'due_minutes' => 120, 'task_title' => 'Задача', 'enabled' => true, 'request_key' => (string) Str::uuid()])->assertOk();
        Schema::drop('user_notifications');
        try {
            app(AutomationService::class)->run($this->site);
            $this->fail('Notification persistence failure must propagate.');
        } catch (QueryException) { /* The surrounding transaction must roll back. */
        }
        $this->assertDatabaseCount('crm_tasks', 0);
        $this->assertDatabaseCount('growth_automation_runs', 0);
    }

    public function test_stale_stage_clock_uses_transition_not_unrelated_edits_and_reentry_is_new_occurrence(): void
    {
        $deal = $this->deal($this->site, ['updated_at' => now()]);
        $stage = DB::table('crm_deals')->where('id', $deal)->value('stage_id');
        $this->postJson($this->path('automation'), ['name' => 'Этап задержался', 'trigger' => 'stale_stage', 'stage_id' => $stage, 'delay_minutes' => 60, 'due_minutes' => 120, 'enabled' => true, 'request_key' => (string) Str::uuid()])->assertOk();
        $this->stageEvent($deal, now()->subHours(3));
        $service = app(AutomationService::class);
        $this->assertSame(1, $service->run($this->site));
        $this->assertSame(0, $service->run($this->site));
        $this->stageEvent($deal, now()->subHours(2));
        $this->assertSame(1, $service->run($this->site));
        DB::table('crm_deals')->where('id', $deal)->update(['paused' => true]);
        $this->stageEvent($deal, now()->subHours(1));
        $this->assertSame(0, $service->run($this->site));
    }

    public function test_confirmed_conversion_queue_upload_poll_and_retry_use_no_form_content(): void
    {
        $deal = $this->configuredDeal();
        $service = app(OfflineConversionService::class);
        $this->assertSame(2, $service->collect($this->site));
        $this->assertSame(0, $service->collect($this->site));
        $this->assertDatabaseCount('growth_metrika_deliveries', 2);
        $payload = DB::table('growth_metrika_deliveries')->where('milestone', 'paid')->first();
        $csv = $service->csv($payload);
        $this->assertStringContainsString('Yclid,Target,DateTime,Price,Currency', $csv);
        $this->assertStringNotContainsString('private@test', $csv);
        $calls = 0;
        Http::fake(function ($r) use (&$calls) {
            if (++$calls === 1) {
                return Http::response(['error' => 'rate_limit'], 429);
            }

            return Http::response(['uploading' => ['id' => 123, 'status' => str_contains($r->url(), '/uploading/') ? 'PROCESSED' : 'UPLOADED', 'line_quantity' => 1]], 200);
        });
        $this->assertSame(2, $service->deliver($this->site));
        $this->assertDatabaseHas('growth_metrika_deliveries', ['status' => 'queued', 'error' => 'metrika_http_429']);
        $this->assertDatabaseHas('growth_metrika_deliveries', ['status' => 'uploaded', 'upload_id' => '123']);
        DB::table('growth_metrika_deliveries')->update(['retry_at' => now()->subMinute()]);
        $service->deliver($this->site);
        $this->assertDatabaseHas('growth_metrika_deliveries', ['status' => 'delivered']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'OAuth test-token') && ! str_contains($r->body(), 'private@test'));
        $response = $this->getJson($this->path('metrika'))->assertOk();
        $this->assertStringNotContainsString('test-token', $response->getContent());
        $this->actingAs($this->manager, 'api')->getJson($this->path('metrika'))->assertForbidden();
        $this->getJson($this->path('insights', $this->other))->assertForbidden();
    }

    public function test_ambiguous_upload_never_retries_automatically_and_requires_explicit_retry_flag(): void
    {
        $this->configuredDeal();
        $service = app(OfflineConversionService::class);
        $service->collect($this->site);
        Http::fake(fn () => throw new ConnectionException('Timeout after sending'));
        $service->deliver($this->site);
        $this->assertSame(0, $service->deliver($this->site));
        $row = DB::table('growth_metrika_deliveries')->first();
        $this->assertSame('uncertain', $row->status);
        $this->postJson($this->path('metrika/'.$row->id.'/retry'), ['request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($this->path('metrika/'.$row->id.'/retry'), ['confirm_possible_duplicate' => true, 'request_key' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseHas('growth_metrika_deliveries', ['id' => $row->id, 'status' => 'queued']);
    }

    public function test_permanent_poll_404_is_actionable_and_recovery_preserves_upload_without_new_post(): void
    {
        $row = $this->acceptedUpload();
        $recovered = false;
        Http::fake(function () use (&$recovered) {
            return $recovered ? Http::response(['uploading' => ['id' => 123, 'status' => 'PROCESSED', 'line_quantity' => 1]], 200) : Http::response([], 404);
        });
        $service = app(OfflineConversionService::class);
        $this->assertSame(1, $service->deliver($this->site));
        $this->assertDatabaseHas('growth_metrika_deliveries', ['id' => $row->id, 'status' => 'failed', 'upload_id' => '123', 'error' => 'metrika_http_404']);
        $this->assertSame(0, $service->deliver($this->site));
        // Current goals may change, but an already accepted file remains an immutable event.
        DB::table('growth_metrika_settings')->where('license_id', $this->site)->update(['paid_goal' => 'changed_goal']);
        $this->postJson($this->path('metrika/'.$row->id.'/retry'), ['request_key' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseHas('growth_metrika_deliveries', ['id' => $row->id, 'status' => 'uploaded', 'upload_id' => '123', 'goal' => 'crm_paid']);
        $recovered = true;
        $service->deliver($this->site);
        $this->assertDatabaseHas('growth_metrika_deliveries', ['id' => $row->id, 'status' => 'delivered', 'upload_id' => '123']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_poll_403_resumes_after_credential_repair_without_reupload(): void
    {
        $row = $this->acceptedUpload();
        Http::fake(fn ($r) => $r->hasHeader('Authorization', 'OAuth repaired-token')
            ? Http::response(['uploading' => ['id' => 123, 'status' => 'PROCESSED', 'line_quantity' => 1]], 200)
            : Http::response([], 403));
        $service = app(OfflineConversionService::class);
        $service->deliver($this->site);
        $this->assertDatabaseHas('growth_metrika_deliveries', ['id' => $row->id, 'status' => 'failed', 'upload_id' => '123', 'error' => 'metrika_http_403']);
        $this->assertSame(0, $service->deliver($this->site));
        $this->postJson($this->path('metrika'), ['counter_id' => '123', 'oauth_token' => 'repaired-token', 'signed_goal' => null, 'paid_goal' => 'crm_paid', 'enabled' => true, 'version' => 1, 'request_key' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseHas('growth_metrika_deliveries', ['id' => $row->id, 'status' => 'uploaded', 'upload_id' => '123']);
        $service->deliver($this->site);
        $this->assertDatabaseHas('growth_metrika_deliveries', ['id' => $row->id, 'status' => 'delivered']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_poll_rate_limit_and_server_error_retry_existing_upload(): void
    {
        $row = $this->acceptedUpload();
        Http::fakeSequence()->push([], 429)->push([], 503)->push(['uploading' => ['id' => 123, 'status' => 'PROCESSED', 'line_quantity' => 1]], 200);
        $service = app(OfflineConversionService::class);
        foreach (['metrika_http_429', 'metrika_http_503'] as $error) {
            $service->deliver($this->site);
            $this->assertDatabaseHas('growth_metrika_deliveries', ['id' => $row->id, 'status' => 'uploaded', 'upload_id' => '123', 'error' => $error]);
            $this->assertSame(0, $service->deliver($this->site));
            DB::table('growth_metrika_deliveries')->where('id', $row->id)->update(['retry_at' => now()->subMinute()]);
        }
        $service->deliver($this->site);
        $this->assertDatabaseHas('growth_metrika_deliveries', ['id' => $row->id, 'status' => 'delivered']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    private function acceptedUpload(): object
    {
        $this->configuredDeal();
        DB::table('growth_metrika_settings')->where('license_id', $this->site)->update(['signed_goal' => null]);
        app(OfflineConversionService::class)->collect($this->site);
        $row = DB::table('growth_metrika_deliveries')->first();
        DB::table('growth_metrika_deliveries')->where('id', $row->id)->update(['status' => 'uploaded', 'upload_id' => '123']);

        return $row;
    }

    private function configuredDeal(): string
    {
        $deal = $this->deal($this->site, ['signed_at' => now()->subDay()->toDateString(), 'contract_number' => '100', 'contract_date' => now()->subDay()->toDateString(), 'amount' => 100]);
        $this->request($this->site, $deal, ['email' => 'private@test.local', 'details' => json_encode(['attribution' => ['yclid' => '123456789']])]);
        $this->payment($this->site, $deal, 'payment', 100);
        DB::table('growth_metrika_settings')->insert(['license_id' => $this->site, 'counter_id' => '123', 'oauth_token' => Crypt::encryptString('test-token'), 'signed_goal' => 'crm_signed', 'paid_goal' => 'crm_paid', 'enabled' => true, 'enabled_at' => now()->subWeek(), 'created_at' => now(), 'updated_at' => now()]);

        return $deal;
    }

    private function path(string $path, ?string $site = null): string
    {
        return '/crm/growth/sites/'.($site ?: $this->site).'/'.$path;
    }

    private function deal(string $site, array $data = []): string
    {
        $client = (string) Str::ulid();
        $id = (string) Str::ulid();
        DB::table('crm_clients')->insert(['id' => $client, 'license_id' => $site, 'name' => 'Client', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('crm_deals')->insert($data + ['id' => $id, 'license_id' => $site, 'client_id' => $client, 'stage_id' => DB::table('crm_stages')->where('license_id', $site)->where('system_key', 'lead')->value('id'), 'title' => 'Order', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function request(string $site, ?string $deal, array $data = []): string
    {
        $id = (string) Str::ulid();
        DB::table('service_requests')->insert($data + ['id' => $id, 'license_id' => $site, 'crm_deal_id' => $deal, 'service_type' => 'consultation', 'name' => 'Private', 'phone' => '79991234567', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function payment(string $site, string $deal, string $kind, int $amount): void
    {
        DB::table('crm_payments')->insert(['id' => (string) Str::ulid(), 'license_id' => $site, 'deal_id' => $deal, 'kind' => $kind, 'amount' => $amount, 'paid_at' => now()->toDateString(), 'comment' => 'Receipt', 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function stageEvent(string $deal, $at): void
    {
        DB::table('crm_events')->insert(['id' => (string) Str::ulid(), 'license_id' => $this->site, 'entity_type' => 'deals', 'entity_id' => $deal, 'deal_id' => $deal, 'action' => 'stage', 'data' => '{}', 'created_at' => $at]);
    }
}
