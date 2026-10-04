<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Growth\EstimateCalculator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GrowthSelectionsEstimateTest extends TestCase
{
    private string $site;

    private string $otherSite;

    private string $project;

    private User $owner;

    private string $editToken;

    protected function setUp(): void
    {
        parent::setUp();
        // Canonical shared migrations are owned by leget-db.
        Schema::create('licenses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('domain');
            $table->unsignedInteger('template_id');
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('active');
            $table->json('catalog_settings')->nullable();
        });
        Schema::create('rubrics', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('slug');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('rubric_id');
            $table->string('slug');
            $table->string('value');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->softDeletes();
        });
        Schema::create('catalog_brands', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('category_id');
            $table->string('slug');
            $table->string('value');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->softDeletes();
        });
        Schema::create('mebel_projects', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('category_id');
            $table->string('license_id')->nullable();
            $table->string('slug');
            $table->string('value');
            $table->string('object_address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        (require base_path('../leget-db/database/migrations/2026_10_03_100001_growth_selections_and_estimates.php'))->up();
        require base_path('routes/growth-selections.php');
        config(['templates' => [1 => ['pages' => ['/' => [], '/mebel/{category}' => [], '/mebel/{category}/{project}' => [], '/stoleshnica/{category}' => []]]]]);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'unused', 'role' => 'client']);
        $this->site = (string) Str::ulid();
        $this->otherSite = (string) Str::ulid();
        $this->project = (string) Str::ulid();
        $this->editToken = str_repeat('a', 64);
        DB::table('licenses')->insert([
            ['id' => $this->site, 'user_id' => $this->owner->id, 'domain' => 'one.example', 'template_id' => 1],
            ['id' => $this->otherSite, 'user_id' => 999, 'domain' => 'two.example', 'template_id' => 1],
        ]);
        DB::table('rubrics')->insert([['id' => 'furniture', 'slug' => 'mebel'], ['id' => 'stone', 'slug' => 'stoleshnica']]);
        DB::table('categories')->insert([['id' => 'kitchen', 'rubric_id' => 'furniture', 'slug' => 'kitchen', 'value' => 'Кухни'], ['id' => 'quartz', 'rubric_id' => 'stone', 'slug' => 'quartz', 'value' => 'Кварц']]);
        DB::table('mebel_projects')->insert(['id' => $this->project, 'category_id' => 'kitchen', 'license_id' => $this->site, 'slug' => 'one', 'value' => '101', 'object_address' => 'Private street, apartment 42']);
    }

    public function test_selection_resolves_only_public_items_and_retries_without_duplicate(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $payload = ['title' => 'Моя кухня', 'creation_key' => (string) Str::uuid(), 'items' => [['kind' => 'project', 'id' => $this->project], ['kind' => 'material', 'id' => 'category:quartz']]];
        $first = $this->withHeaders(['X-Selection-Edit-Token' => $this->editToken])->postJson('/growth/selections?domain=one.example', $payload)->assertCreated();
        $token = $first->json('token');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $token);
        $this->postJson('/growth/selections?domain=one.example', $payload)->assertCreated()->assertJsonPath('token', $token);
        $response = $this->getJson('/growth/selections/'.$token.'?domain=one.example')->assertOk()->assertJsonPath('can_manage', true)->assertJsonCount(2, 'items');
        $this->assertStringNotContainsString('Private street', $response->getContent());
        $this->assertStringNotContainsString('edit_token_hash', $response->getContent());
        $this->assertDatabaseCount('project_selections', 1);
        $this->postJson('/growth/selections?domain=one.example', array_replace($payload, ['title' => 'Changed']))->assertUnprocessable();
        $this->getJson('/growth/selections/'.$token.'?domain=two.example')->assertNotFound();
        $this->postJson('/growth/selections?domain=two.example', array_replace($payload, ['creation_key' => (string) Str::uuid()]))->assertUnprocessable();
        DB::table('mebel_projects')->where('id', $this->project)->update(['is_active' => false]);
        $this->getJson('/growth/selections/'.$token.'?domain=one.example')->assertOk()->assertJsonCount(1, 'items');
    }

    public function test_comments_are_idempotent_and_expiration_and_revocation_close_access(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $token = $this->createSelection();
        $comment = ['author' => 'Анна', 'body' => 'Нравится планировка', 'submission_key' => (string) Str::uuid()];
        $first = $this->postJson('/growth/selections/'.$token.'/comments?domain=one.example', $comment)->assertCreated();
        $this->postJson('/growth/selections/'.$token.'/comments?domain=one.example', $comment)->assertCreated()->assertJsonPath('id', $first->json('id'));
        $this->postJson('/growth/selections/'.$token.'/comments?domain=one.example', array_replace($comment, ['body' => 'Changed']))->assertUnprocessable();
        $this->assertDatabaseCount('project_selection_comments', 1);
        $this->withHeaders(['X-Selection-Edit-Token' => str_repeat('b', 64)])->postJson('/growth/selections/'.$token.'/revoke?domain=one.example')->assertForbidden();
        $this->withHeaders(['X-Selection-Edit-Token' => $this->editToken])->postJson('/growth/selections/'.$token.'/revoke?domain=one.example')->assertOk();
        $this->getJson('/growth/selections/'.$token.'?domain=one.example')->assertNotFound();
        $another = $this->createSelection();
        $this->travel(31)->days();
        $this->getJson('/growth/selections/'.$another.'?domain=one.example')->assertNotFound();
    }

    public function test_calculator_requires_owner_rules_and_denies_foreign_settings(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->getJson('/growth/estimate?domain=one.example')->assertOk()->assertJsonPath('available', false);
        $input = ['run_cm' => 300, 'layout' => 'l', 'material' => 'paint', 'equipment' => 1];
        $this->postJson('/growth/estimate?domain=one.example', ['inputs' => $input])->assertUnprocessable();
        $this->actingAs($this->owner, 'api')->postJson('/crm/sites/'.$this->otherSite.'/calculator', ['enabled' => true, 'rules' => $this->rules()])->assertForbidden();
        $save = ['enabled' => true, 'rules' => $this->rules(), 'save_key' => (string) Str::uuid(), 'expected_version' => null];
        $saved = $this->postJson('/crm/sites/'.$this->site.'/calculator', $save)->assertOk();
        $this->postJson('/crm/sites/'.$this->site.'/calculator', $save)->assertOk()->assertJsonPath('version', $saved->json('version'));
        $changed = $save;
        $changed['rules']['base_min'] = 11000;
        $this->postJson('/crm/sites/'.$this->site.'/calculator', $changed)->assertStatus(409);
        $stale = $save;
        $stale['save_key'] = (string) Str::uuid();
        $this->postJson('/crm/sites/'.$this->site.'/calculator', $stale)->assertStatus(409);
        $this->postJson('/growth/estimate?domain=one.example', ['inputs' => $input, 'min' => 1])->assertOk()->assertJsonPath('min', 77000)->assertJsonPath('max', 115000);
        $this->postJson('/growth/estimate?domain=two.example', ['inputs' => $input])->assertUnprocessable();
        $this->postJson('/growth/estimate?domain=one.example', ['inputs' => array_replace($input, ['material' => 'unknown'])])->assertUnprocessable();
        $invalid = $this->rules();
        $invalid['base_max'] = 1;
        $this->postJson('/crm/sites/'.$this->site.'/calculator', ['enabled' => true, 'rules' => $invalid, 'save_key' => (string) Str::uuid(), 'expected_version' => $saved->json('version')])->assertUnprocessable()->assertJsonValidationErrors('base_max');
    }

    public function test_numeric_boundaries_and_shared_formula(): void
    {
        $rules = EstimateCalculator::rules($this->rules());
        $this->assertSame(10000.0, $rules['base_min']);
        $this->assertSame(77000, EstimateCalculator::calculate($rules, ['run_cm' => 300, 'layout' => 'l', 'material' => 'paint', 'equipment' => 1])['min']);
        $this->expectException(ValidationException::class);
        EstimateCalculator::calculate($rules, ['run_cm' => 3001, 'layout' => 'l', 'material' => 'paint', 'equipment' => 1]);
    }

    public function test_public_creation_rate_limit_has_its_own_counter(): void
    {
        $payload = ['title' => 'Кухня', 'creation_key' => (string) Str::uuid(), 'items' => [['kind' => 'project', 'id' => $this->project]]];
        $this->withHeaders(['X-Selection-Edit-Token' => $this->editToken]);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/growth/selections?domain=one.example', $payload)->assertCreated();
        }
        $this->postJson('/growth/selections?domain=one.example', $payload)->assertStatus(429);
        $this->getJson('/growth/selection-catalog?domain=one.example')->assertOk();
        $this->assertDatabaseCount('project_selections', 1);
    }

    private function createSelection(): string
    {
        return $this->withHeaders(['X-Selection-Edit-Token' => $this->editToken])->postJson('/growth/selections?domain=one.example', ['title' => 'Кухня', 'creation_key' => (string) Str::uuid(), 'items' => [['kind' => 'project', 'id' => $this->project]]])->assertCreated()->json('token');
    }

    private function rules(): array
    {
        return ['base_min' => 10000, 'base_max' => 15000, 'equipment_min' => 5000, 'equipment_max' => 7000,
            'layouts' => ['straight' => 1, 'l' => 1.2, 'u' => 1.3, 'island' => 1.4],
            'materials' => [['key' => 'paint', 'label' => 'Эмаль', 'multiplier' => 2]], 'note' => 'Тестовые ставки, не предложение'];
    }
}
