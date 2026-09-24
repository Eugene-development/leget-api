<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\Crm\CrmDefaults;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmTest extends TestCase
{
    private User $owner;

    private User $manager;

    private string $site;

    private string $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('licenses', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name')->default('Сайт');
            $t->string('domain');
            $t->unsignedBigInteger('user_id');
        });
        foreach (['2026_05_21_000001_create_service_requests_table.php', '2026_08_08_000001_create_conversions_table.php', '2026_09_15_120000_unify_form_submissions.php', '2026_09_23_180000_create_crm_tables.php', '2026_09_23_180100_snapshot_crm_template_metadata.php'] as $f) {
            (require base_path('../leget-db/database/migrations/'.$f))->up();
        }
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secret']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'manager@example.test', 'password' => 'secret']);
        $this->manager->forceFill(['role' => Role::Manager])->save();
        $this->site = (string) Str::ulid();
        $this->foreignSite = (string) Str::ulid();
        DB::table('licenses')->insert([['id' => $this->site, 'domain' => 'one.test', 'user_id' => $this->owner->id], ['id' => $this->foreignSite, 'domain' => 'two.test', 'user_id' => $this->owner->id]]);
        DB::table('crm_memberships')->insert(['id' => (string) Str::ulid(), 'license_id' => $this->site, 'user_id' => $this->manager->id, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        app(CrmDefaults::class)->seed($this->site);
        app(CrmDefaults::class)->seed($this->foreignSite);
        Storage::fake('crm-test');
        config(['crm.document_disk' => 'crm-test']);
        $this->actingAs($this->manager, 'api');
    }

    private function postCrm(string $path, array $body = [], ?string $site = null)
    {
        return $this->postJson('/crm/sites/'.($site ?? $this->site).'/'.$path, $body + ['request_key' => (string) Str::uuid()]);
    }

    private function client(?string $site = null): array
    {
        return $this->postCrm('clients', ['kind' => 'person', 'name' => 'Иван Петров', 'phone' => '8 (999) 111-22-33'], $site)->assertOk()->json('item');
    }

    private function deal(): array
    {
        return $this->postCrm('deals', ['client_id' => $this->client()['id'], 'title' => 'Кухня', 'amount' => '100.10'])->assertOk()->json('item');
    }

    private function stage(string $key): string
    {
        return DB::table('crm_stages')->where('license_id', $this->site)->where('system_key', $key)->value('id');
    }

    public function test_revocation_and_site_isolation(): void
    {
        $this->getJson('/crm/sites/'.$this->foreignSite.'/dashboard')->assertForbidden();
        $this->actingAs($this->owner, 'api');
        $foreign = $this->client($this->foreignSite);
        $this->actingAs($this->manager, 'api');
        $this->getJson('/crm/sites/'.$this->site.'/clients/'.$foreign['id'])->assertNotFound();
        $this->getJson('/crm/sites/'.$this->site.'/duplicates?phone=79991112233')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/crm/sites/'.$this->site.'/clients?search=Иван')->assertOk()->assertJsonPath('items.total', 0);
        $this->postCrm('deals', ['client_id' => $foreign['id'], 'title' => 'Bad', 'amount' => '1'])->assertNotFound();
        $this->postCrm('settings', ['version' => 1, 'requisites' => ['name' => 'Bad']])->assertForbidden();
        DB::table('crm_memberships')->where('license_id', $this->site)->update(['status' => 'revoked']);
        $this->getJson('/crm/sites/'.$this->site.'/dashboard')->assertForbidden();
    }

    public function test_rating_validation_history_and_concurrency(): void
    {
        $c = $this->client();
        $url = 'clients/'.$c['id'].'/rate';
        $this->postCrm($url, ['version' => 1, 'rating' => 11, 'rating_comment' => 'Причина'])->assertUnprocessable();
        $this->postCrm($url, ['version' => 1, 'rating' => 8])->assertUnprocessable();
        $this->postCrm($url, ['version' => 1, 'rating' => 8, 'rating_comment' => 'Все договорённости соблюдены'])->assertOk();
        $this->postCrm($url, ['version' => 1, 'rating' => 6, 'rating_comment' => 'Устаревшая карточка'])->assertConflict();
        $this->getJson('/crm/sites/'.$this->site.'/clients/'.$c['id'])->assertOk()->assertJsonPath('item.rating', 8)->assertJsonPath('history.total', 2);
    }

    public function test_idempotent_creation_and_payload_conflict(): void
    {
        $p = ['kind' => 'person', 'name' => 'Повтор', 'request_key' => (string) Str::uuid()];
        $a = $this->postCrm('clients', $p)->assertOk();
        $this->postCrm('clients', $p)->assertOk()->assertJsonPath('item.id', $a->json('item.id'));
        $this->postCrm('clients', array_replace($p, ['name' => 'Другое']))->assertConflict();
        $this->assertDatabaseCount('crm_clients', 1);
    }

    public function test_full_deal_requires_signature_payment_acceptance_and_reopens_with_reason(): void
    {
        $d = $this->deal();
        $url = 'deals/'.$d['id'];
        $this->postCrm($url.'/stage', ['version' => 1, 'stage_id' => $this->stage('won')])->assertUnprocessable();
        $d = $this->postCrm($url, ['version' => 1, 'client_id' => $d['client_id'], 'title' => 'Кухня', 'amount' => '100.10', 'contract_number' => 'Д-1', 'contract_date' => '2026-09-23', 'signed_at' => '2026-09-23', 'accepted_at' => '2026-09-24'])->assertOk()->json('item');
        $this->postCrm($url.'/stage', ['version' => $d['version'], 'stage_id' => $this->stage('won')])->assertUnprocessable();
        $d = $this->postCrm($url.'/payment', ['version' => $d['version'], 'kind' => 'payment', 'amount' => '100.10', 'paid_at' => '2026-09-23', 'comment' => 'Оплата по договору'])->assertOk()->json('item');
        $d = $this->postCrm($url.'/stage', ['version' => $d['version'], 'stage_id' => $this->stage('won')])->assertOk()->json('item');
        $this->postCrm($url.'/stage', ['version' => $d['version'], 'stage_id' => $this->stage('execution')])->assertUnprocessable();
        $this->postCrm($url.'/stage', ['version' => $d['version'], 'stage_id' => $this->stage('execution'), 'reason' => 'Нужна корректировка'])->assertOk();
        $this->getJson('/crm/sites/'.$this->site.'/'.$url)->assertOk()->assertJsonPath('paid_total', '100.10');
    }

    public function test_incoming_conversion_is_idempotent(): void
    {
        $c = $this->client();
        $id = (string) Str::ulid();
        DB::table('service_requests')->insert(['id' => $id, 'license_id' => $this->site, 'name' => 'Иван', 'phone' => '123', 'service_type' => 'contact', 'created_at' => now(), 'updated_at' => now()]);
        $p = ['version' => 1, 'client_id' => $c['id'], 'title' => 'Из заявки', 'request_key' => (string) Str::uuid()];
        $a = $this->postCrm('incoming/'.$id.'/convert', $p)->assertOk();
        $this->postCrm('incoming/'.$id.'/convert', $p)->assertOk()->assertJsonPath('deal_id', $a->json('deal_id'));
        $this->assertDatabaseCount('crm_deals', 1);
    }

    public function test_owner_reviews_and_role_survives_other_site(): void
    {
        $m = DB::table('crm_memberships')->first();
        $this->postCrm('members/'.$m->id.'/revoke', ['version' => 1, 'reason' => 'Отзыв'])->assertForbidden();
        DB::table('crm_memberships')->insert(['id' => (string) Str::ulid(), 'license_id' => $this->foreignSite, 'user_id' => $this->manager->id, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->owner, 'api');
        $this->postCrm('members/'.$m->id.'/revoke', ['version' => 1, 'reason' => 'Завершение работы'])->assertOk();
        $this->assertSame(Role::Manager, $this->manager->fresh()->role);
        $other = DB::table('crm_memberships')->where('license_id', $this->foreignSite)->first();
        $this->postCrm('members/'.$other->id.'/revoke', ['version' => 1, 'reason' => 'Завершение работы'], $this->foreignSite)->assertOk();
        $this->assertSame(Role::Client, $this->manager->fresh()->role);
    }

    public function test_documents_encrypted_versioned_safe_and_scoped(): void
    {
        $d = $this->deal();
        $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Договор с '], ['type' => 'crmField', 'attrs' => ['field' => 'client.name']]]]]];
        $this->actingAs($this->owner, 'api');
        $t = $this->postCrm('templates', ['name' => 'Договор', 'kind' => 'contract', 'content' => $content])->assertOk()->json('item');
        $this->postCrm('templates/'.$t['id'].'/publish', ['version' => 1])->assertOk();
        $this->actingAs($this->manager, 'api');
        $doc = $this->postCrm('deals/'.$d['id'].'/documents', ['version' => 1, 'template_id' => $t['id']])->assertOk()->json('item');
        $this->assertSame('Иван Петров', $doc['snapshot']['values']['client.name']);
        $stored = DB::table('crm_documents')->where('id', $doc['id'])->first();
        $this->assertStringNotContainsString('%PDF', Storage::disk($stored->disk)->get($stored->path));
        $this->get('/crm/sites/'.$this->site.'/documents/'.$doc['id'].'/download')->assertOk();
        $this->getJson('/crm/sites/'.$this->foreignSite.'/documents/'.$doc['id'].'/download')->assertForbidden();
        $this->actingAs($this->owner, 'api');
        $this->postCrm('templates', ['name' => 'Bad', 'kind' => 'act', 'content' => ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => 'file:///etc/passwd']]]]])->assertUnprocessable();
    }

    public function test_closed_deal_cannot_lose_payment_milestones_and_refund_is_bounded(): void
    {
        $d = $this->deal();
        $url = 'deals/'.$d['id'];
        $this->postCrm($url.'/payment', ['version' => 1, 'kind' => 'refund', 'amount' => '1', 'paid_at' => '2026-09-23', 'comment' => 'Попытка возврата'])->assertUnprocessable();
        $this->postCrm($url.'/stage', ['version' => 1, 'stage_id' => $this->stage('lost')])->assertUnprocessable();
        $d = $this->postCrm($url.'/stage', ['version' => 1, 'stage_id' => $this->stage('lost'), 'reason' => 'Клиент отказался'])->assertOk()->json('item');
        $this->postCrm($url, ['version' => $d['version'], 'client_id' => $d['client_id'], 'title' => 'Изменение закрытой', 'amount' => '1'])->assertConflict();
    }

    public function test_two_companies_tasks_and_client_history_are_linked_without_partner_access(): void
    {
        $d = $this->deal();
        foreach (['Производитель', 'Монтажная бригада'] as $name) {
            $company = $this->postCrm('companies', ['name' => $name])->assertOk()->json('item');
            $d = $this->postCrm('deals/'.$d['id'].'/company', ['version' => $d['version'], 'company_id' => $company['id'], 'role' => $name, 'status' => 'working', 'responsibility' => 'Выполнить работы'])->assertOk()->json('item');
        }
        $task = $this->postCrm('tasks', ['title' => 'Согласовать выезд', 'kind' => 'call', 'assigned_to' => $this->manager->id, 'due_at' => '2026-09-25T12:00', 'deal_id' => $d['id']])->assertOk()->json('item');
        $this->postCrm('tasks/'.$task['id'].'/complete', ['version' => 1, 'completed' => true])->assertOk();
        $this->getJson('/crm/sites/'.$this->site.'/deals/'.$d['id'])->assertOk()->assertJsonCount(2, 'companies')->assertJsonCount(1, 'tasks');
        $this->getJson('/crm/sites/'.$this->site.'/clients/'.$d['client_id'])->assertOk()->assertJsonPath('history.total', 6);
    }

    public function test_template_snapshots_are_immutable_and_missing_fields_block_pdf(): void
    {
        $d = $this->deal();
        $this->actingAs($this->owner, 'api');
        $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'crmField', 'attrs' => ['field' => 'company.inn']]]]]];
        $t = $this->postCrm('templates', ['name' => 'Первое имя', 'kind' => 'contract', 'content' => $content])->assertOk()->json('item');
        $t = $this->postCrm('templates/'.$t['id'].'/publish', ['version' => 1])->assertOk()->json('item');
        $this->postCrm('deals/'.$d['id'].'/documents', ['version' => 1, 'template_id' => $t['id']])->assertUnprocessable();
        $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Первый текст']]]]];
        $t = $this->postCrm('templates/'.$t['id'], ['version' => $t['version'], 'name' => 'Первое имя', 'kind' => 'contract', 'content' => $content])->assertOk()->json('item');
        $t = $this->postCrm('templates/'.$t['id'].'/publish', ['version' => $t['version']])->assertOk()->json('item');
        $doc = $this->postCrm('deals/'.$d['id'].'/documents', ['version' => 1, 'template_id' => $t['id']])->assertOk()->json('item');
        $stored = DB::table('crm_documents')->where('id', $doc['id'])->first();
        $before = Storage::disk($stored->disk)->get($stored->path);
        $this->postCrm('templates/'.$t['id'], ['version' => $t['version'], 'name' => 'Новое имя', 'kind' => 'act', 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Новый текст']]]]]])->assertOk();
        $second = $this->postCrm('deals/'.$d['id'].'/documents', ['version' => 1, 'template_id' => $t['id']])->assertOk()->json('item');
        $this->assertSame('Первое имя.pdf', $second['name']);
        $this->assertSame('contract', $second['kind']);
        $this->assertSame($before, Storage::disk($stored->disk)->get($stored->path));
        $this->assertSame('Первый текст', $second['snapshot']['content']['content'][0]['content'][0]['text']);
    }

    public function test_archive_moves_open_deals_with_audit_and_preserves_system_stages(): void
    {
        $d = $this->deal();
        $this->actingAs($this->owner, 'api');
        $stage = $this->postCrm('stages', ['name' => 'Пробный этап', 'sort_order' => 25])->assertOk()->json('item');
        $this->postCrm('deals/'.$d['id'].'/stage', ['version' => 1, 'stage_id' => $stage['id']])->assertOk();
        $this->postCrm('stages/'.$stage['id'].'/archive', ['version' => 1])->assertUnprocessable();
        $this->postCrm('stages/'.$stage['id'].'/archive', ['version' => 1, 'replacement_id' => $this->stage('contact')])->assertOk();
        $this->assertDatabaseHas('crm_deals', ['id' => $d['id'], 'stage_id' => $this->stage('contact'), 'version' => 3]);
        $this->assertDatabaseHas('crm_events', ['entity_id' => $d['id'], 'action' => 'stage_transferred']);
        $this->postCrm('stages/'.$this->stage('lead').'/archive',['version' => 1])->assertUnprocessable();
    }
}
