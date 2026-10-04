<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Crm\CrmDefaults;
use App\Services\Crm\CrmStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderPortalTest extends TestCase
{
    private User $owner;

    private User $stranger;

    private string $site;

    private string $otherSite;

    private object $deal;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('licenses', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->unsignedBigInteger('user_id');
            $t->string('domain');
            $t->string('name')->default('Мебель');
            $t->json('header_data')->nullable();
            $t->json('footer_data')->nullable();
        });
        foreach (['2026_05_21_000001_create_service_requests_table.php', '2026_08_08_000001_create_conversions_table.php', '2026_09_15_120000_unify_form_submissions.php', '2026_09_23_180000_create_crm_tables.php', '2026_10_03_100003_order_portal_and_launch_checks.php'] as $migration) {
            (require base_path('../leget-db/database/migrations/'.$migration))->up();
        }
        $this->owner = User::create(['name' => 'Владелец', 'email' => 'owner@portal.test', 'password' => 'secret']);
        $this->stranger = User::create(['name' => 'Другой', 'email' => 'stranger@portal.test', 'password' => 'secret']);
        $this->site = (string) Str::ulid();
        $this->otherSite = (string) Str::ulid();
        DB::table('licenses')->insert([['id' => $this->site, 'domain' => 'furniture.test', 'user_id' => $this->owner->id], ['id' => $this->otherSite, 'domain' => 'other.test', 'user_id' => $this->stranger->id]]);
        app(CrmDefaults::class)->seed($this->site);
        $store = app(CrmStore::class);
        $client = $store->insert('crm_clients', $this->site, ['name' => 'Заказчик', 'email' => 'client@portal.test', 'rating' => 3, 'rating_comment' => 'Внутренняя оценка']);
        $this->deal = $store->insert('crm_deals', $this->site, ['client_id' => $client->id, 'stage_id' => DB::table('crm_stages')->where('license_id', $this->site)->where('system_key', 'execution')->value('id'), 'title' => 'Кухня', 'amount' => '100000', 'description' => 'Внутренняя заметка']);
        Storage::fake('portal-test');
        $this->actingAs($this->owner, 'api');
    }

    private function endpoint(string $action = ''): string
    {
        return '/crm/portal/sites/'.$this->site.'/deals/'.$this->deal->id.($action ? '/'.$action : '');
    }

    private function invitation(): array
    {
        return $this->postJson($this->endpoint('invite'), ['request_key' => (string) Str::uuid()])->assertOk()->json();
    }

    private function exchangeSession(string $token): string
    {
        return $this->postJson('/growth/orders/exchange', ['token' => $token, 'domain' => 'furniture.test', 'exchange_key' => (string) Str::uuid()])->assertOk()->json('session');
    }

    private function token(array $invitation): string
    {
        return basename($invitation['path']);
    }

    private function portal(string $session)
    {
        return $this->withHeader('Authorization', 'Bearer '.$session)->getJson('/growth/orders/session?domain=furniture.test');
    }

    public function test_site_and_order_access_are_scoped_and_plan_is_explicit(): void
    {
        $this->actingAs($this->stranger, 'api')->getJson($this->endpoint())->assertForbidden();
        $this->actingAs($this->owner, 'api')->getJson($this->endpoint())->assertOk()->assertJsonCount(4, 'milestones')->assertJsonPath('milestones.0.status', 'planned');
        $this->getJson('/crm/portal/sites/'.$this->site.'/deals/'.Str::ulid())->assertNotFound();
        $this->actingAs($this->stranger, 'api')->postJson($this->endpoint('invite'), ['request_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_invite_retry_and_exchange_retry_preserve_secrets_without_exposing_internal_crm(): void
    {
        $body = ['request_key' => (string) Str::uuid()];
        $first = $this->postJson($this->endpoint('invite'), $body)->assertOk()->json();
        $this->postJson($this->endpoint('invite'), $body)->assertOk()->assertJsonPath('path', $first['path']);
        $token = $this->token($first);
        $exchange = ['token' => $token, 'domain' => 'furniture.test', 'exchange_key' => (string) Str::uuid()];
        $session = $this->postJson('/growth/orders/exchange', $exchange)->assertOk()->json('session');
        $this->postJson('/growth/orders/exchange', $exchange)->assertOk()->assertJsonPath('session', $session);
        $this->postJson('/growth/orders/exchange', array_replace($exchange, ['exchange_key' => (string) Str::uuid()]))->assertConflict();
        $content = $this->portal($session)->assertOk()->assertJsonPath('order.title', 'Кухня')->json();
        $this->assertArrayNotHasKey('description', $content['order']);
        $this->assertArrayNotHasKey('rating', $content['client']);
        $this->assertStringNotContainsString($token, json_encode(DB::table('crm_operations')->get()));
        $this->assertStringNotContainsString($session, json_encode(DB::table('crm_order_access')->get()));
        $this->withHeader('Authorization', 'Bearer '.$session)->getJson('/growth/orders/session?domain=other.test')->assertUnauthorized();
    }

    public function test_revocation_rotation_and_expiration_apply_to_sessions(): void
    {
        $first = $this->invitation();
        $session = $this->exchangeSession($this->token($first));
        $second = $this->invitation();
        $this->portal($session)->assertUnauthorized();
        $this->getJson('/growth/orders/invitation/'.$this->token($first).'?domain=furniture.test')->assertNotFound();
        $active = $this->exchangeSession($this->token($second));
        $this->postJson($this->endpoint('revoke'), ['request_key' => (string) Str::uuid()])->assertOk();
        $this->portal($active)->assertUnauthorized();
        $third = $this->invitation();
        $this->travel(8)->days();
        $this->getJson('/growth/orders/invitation/'.$this->token($third).'?domain=furniture.test')->assertNotFound();
        $this->travelBack();
        $fourth = $this->invitation();
        $active = $this->exchangeSession($this->token($fourth));
        $this->travel(31)->days();
        $this->portal($active)->assertUnauthorized();
    }

    private function document(string $site, string $deal, string $bytes): object
    {
        $path = Str::random(20).'.enc';
        Storage::disk('portal-test')->put($path, Crypt::encryptString($bytes));

        return app(CrmStore::class)->insert('crm_documents', $site, ['deal_id' => $deal, 'name' => 'Договор.pdf', 'kind' => 'contract', 'disk' => 'portal-test', 'path' => $path, 'mime' => 'application/pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'created_by' => $this->owner->id]);
    }

    public function test_only_explicit_documents_are_shared_and_plan_has_optimistic_concurrency(): void
    {
        $doc = $this->document($this->site, $this->deal->id, 'private pdf');
        $plan = $this->getJson($this->endpoint())->assertOk()->json();
        $session = $this->exchangeSession($this->token($this->invitation()));
        $this->withHeader('Authorization', 'Bearer '.$session)->getJson('/growth/orders/documents/'.$doc->id.'?domain=furniture.test')->assertNotFound();
        $body = ['request_key' => (string) Str::uuid(), 'version' => $plan['plan']['version'], 'document_ids' => [$doc->id], 'milestones' => array_map(fn ($m) => ['id' => $m['id'], 'status' => 'completed', 'due_at' => '2026-10-20', 'note' => 'Согласовано с заказчиком'], $plan['milestones'])];
        $this->postJson($this->endpoint('plan'), $body)->assertOk()->assertJsonPath('version', 2);
        $this->postJson($this->endpoint('plan'), $body)->assertOk()->assertJsonPath('version', 2);
        $this->postJson($this->endpoint('plan'), array_replace($body, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $response = $this->withHeader('Authorization', 'Bearer '.$session)->get('/growth/orders/documents/'.$doc->id.'?domain=furniture.test')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('private pdf', $response->getContent());
        $this->portal($session)->assertOk()->assertJsonPath('milestones.0.status', 'completed')->assertJsonCount(1, 'documents');
        $body['version'] = 2;
        $body['request_key'] = (string) Str::uuid();
        $body['document_ids'] = [];
        $this->postJson($this->endpoint('plan'), $body)->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$session)->getJson('/growth/orders/documents/'.$doc->id.'?domain=furniture.test')->assertNotFound();
    }

    public function test_scoped_paginated_deals_make_old_orders_reachable_after_one_hundred_records(): void
    {
        $store = app(CrmStore::class);
        for ($i = 0; $i < 105; $i++) {
            $store->insert('crm_deals', $this->site, ['client_id' => $this->deal->client_id, 'stage_id' => $this->deal->stage_id, 'title' => 'Новый заказ '.$i]);
        }
        $page = $this->getJson('/crm/sites/'.$this->site.'/deals?per_page=30&page=4')->assertOk()->assertJsonPath('items.total', 106)->assertJsonPath('items.last_page', 4);
        $this->assertContains($this->deal->id, array_column($page->json('items.data'), 'id'));
        $this->getJson('/crm/sites/'.$this->site.'/deals?per_page=30&search='.urlencode('Кухня'))->assertOk()->assertJsonPath('items.total', 1)->assertJsonPath('items.data.0.id', $this->deal->id);
        $this->actingAs($this->stranger, 'api')->getJson('/crm/sites/'.$this->site.'/deals?per_page=30&page=4')->assertForbidden();
    }

    public function test_launch_checks_distinguish_settings_manual_review_and_delivery(): void
    {
        $url = '/crm/portal/sites/'.$this->site.'/launch';
        $this->actingAs($this->stranger, 'api')->getJson($url)->assertForbidden();
        $first = $this->actingAs($this->owner, 'api')->getJson($url)->assertOk()->json();
        $checks = collect($first['checks'])->keyBy('key');
        $this->assertFalse($checks['form_storage']['ready']);
        $this->assertFalse($checks['phone']['ready']);
        DB::table('licenses')->where('id', $this->site)->update(['header_data' => json_encode(['phone' => '+7 495 123-45-67', 'yandexMetrica' => '12345']), 'footer_data' => json_encode(['email' => 'hello@furniture.test'])]);
        DB::table('service_requests')->insert(['id' => (string) Str::ulid(), 'license_id' => $this->site, 'service_type' => 'consultation', 'name' => 'Тест', 'phone' => '+74951234567', 'mail_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $checks = collect($this->getJson($url)->assertOk()->json('checks'))->keyBy('key');
        $this->assertTrue($checks['phone']['ready']);
        $this->assertTrue($checks['form_storage']['ready']);
        $this->assertFalse($checks['form_delivery']['ready']);
        $body = ['request_key' => (string) Str::uuid(), 'check_key' => 'mobile_reviewed', 'checked' => true];
        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk();
        $this->assertDatabaseCount('site_launch_checks', 1);
        $this->postJson($url, ['request_key' => (string) Str::uuid(), 'check_key' => 'form_delivery', 'checked' => true])->assertUnprocessable();
    }
}
