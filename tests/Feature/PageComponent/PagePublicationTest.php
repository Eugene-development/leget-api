<?php

namespace Tests\Feature\PageComponent;

use App\Exceptions\GraphQLException;
use App\GraphQL\Queries\RenderPage;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\User;
use App\Services\PagePublicationService;
use App\Services\TemplateService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PagePublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private License $license;

    private PagePublicationService $publication;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('licenses', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('domain');
            $table->unsignedInteger('template_id');
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('active');
            $table->string('name')->nullable();
            $table->string('meta_description')->nullable();
            $table->json('header_data')->nullable();
            $table->json('footer_data')->nullable();
            $table->timestamps();
        });
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->char('license_id', 26);
            $table->string('slug');
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('seo_keywords')->nullable();
            $table->json('component_order')->nullable();
            $table->timestamps();
            $table->unique(['license_id', 'slug']);
        });
        (require base_path('../leget-db/database/migrations/2026_10_03_100002_page_drafts_and_revisions.php'))->up();
        $this->owner = User::factory()->create();
        $this->license = License::create([
            'user_id' => $this->owner->id, 'domain' => 'publication.example.test', 'template_id' => 999,
            'header_data' => ['phone' => 'Published phone'],
        ]);
        config(['templates' => [999 => ['pages' => [
            '/test' => [
                ['type' => 'Hero', 'defaults' => ['title' => 'Default title']],
                ['type' => 'Text', 'defaults' => ['title' => 'Text title']],
            ],
            '/items/{item}' => [['type' => 'Hero', 'defaults' => ['title' => 'Item']]],
            '__global__' => [['type' => 'Footer', 'defaults' => ['label' => 'Footer']]],
        ]]]]);
        $this->publication = app(PagePublicationService::class);
    }

    private function begin(): array
    {
        return $this->publication->begin($this->owner, $this->license->id, '/test');
    }

    private function edit(array $draft, array $operation): array
    {
        return $this->publication->edit($this->owner, $this->license->id, $draft['id'], $draft['version'], $operation);
    }

    public function test_draft_isolated_from_live_and_defaults_stay_lazy_on_publish(): void
    {
        $draft = $this->begin();
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('page_components', 0);
        $draft = $this->edit($draft, ['kind' => 'component', 'type' => 'Hero', 'data' => ['title' => 'Draft title']]);
        $draft = $this->edit($draft, ['kind' => 'move', 'type' => 'Text', 'direction' => 'up']);
        $draft = $this->edit($draft, ['kind' => 'seo', 'title' => 'Prepared title']);
        $this->assertDatabaseCount('page_components', 0);
        Cache::tags(["license:{$this->license->id}"])->put('render:fixture', 'stale');
        $this->publication->publish($this->owner, $this->license->id, $draft['id'], $draft['version']);
        $page = Page::firstOrFail();
        $this->assertSame('Prepared title', $page->seo_title);
        $this->assertSame(['Text', 'Hero'], $page->component_order);
        $this->assertDatabaseCount('page_components', 1);
        $this->assertDatabaseCount('page_drafts', 0);
        $this->assertDatabaseCount('page_revisions', 2);
        $this->assertNull(Cache::tags(["license:{$this->license->id}"])->get('render:fixture'));
        config(['templates.999.pages./test' => [
            ['type' => 'Hero', 'defaults' => ['title' => 'New default']],
            ['type' => 'Text', 'defaults' => ['title' => 'Updated lazy text']],
        ]]);
        $merged = app(TemplateService::class)->getMergedPageComponents($this->license->id, $page);
        $this->assertSame('Updated lazy text', $merged->firstWhere('type', 'Text')->data['title']);
    }

    public function test_stale_draft_version_and_changed_live_page_are_rejected(): void
    {
        $draft = $this->begin();
        $new = $this->edit($draft, ['kind' => 'component', 'type' => 'Hero', 'data' => ['title' => 'Prepared']]);
        try {
            $this->publication->publish($this->owner, $this->license->id, $draft['id'], $draft['version']);
            $this->fail('Expected stale version conflict');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->license->header_data = ['phone' => 'New live phone'];
        $this->license->save();
        try {
            $this->publication->publish($this->owner, $this->license->id, $new['id'], $new['version']);
            $this->fail('Expected published state conflict');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('page_drafts', 1);
        $this->assertSame('New live phone', $this->license->fresh()->header_data['phone']);
    }

    public function test_a_second_publisher_cannot_publish_deleted_draft(): void
    {
        $draft = $this->begin();
        $this->publication->publish($this->owner, $this->license->id, $draft['id'], 1);
        $this->expectException(HttpException::class);
        $this->publication->publish($this->owner, $this->license->id, $draft['id'], 1);
    }

    public function test_other_owner_and_other_license_cannot_use_draft(): void
    {
        $draft = $this->begin();
        $other = User::factory()->create();
        $otherLicense = License::create(['user_id' => $other->id, 'domain' => 'other.example.test', 'template_id' => 999]);
        try {
            $this->publication->edit($other, $this->license->id, $draft['id'], 1, ['kind' => 'seo']);
            $this->fail('Expected ownership check');
        } catch (ModelNotFoundException) {
        }
        try {
            $this->publication->edit($other, $otherLicense->id, $draft['id'], 1, ['kind' => 'seo']);
            $this->fail('Expected tenant isolation');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
    }

    public function test_preview_is_expiring_site_bound_revocable_and_merges_current_defaults(): void
    {
        $draft = $this->begin();
        $request = Request::create('/graphql');
        $request->headers->set('X-Leget-Draft', $draft['previewToken']);
        $snapshot = $this->publication->forRender($request, $this->license);
        $this->assertSame([], $snapshot['components']);
        $page = new Page(['license_id' => $this->license->id, 'slug' => '/test']);
        $merged = app(TemplateService::class)->getMergedPageComponents($this->license->id, $page, '/test', $snapshot['components']);
        $this->assertSame('Default title', $merged->first()->data['title']);
        $this->assertFalse($merged->first()->exists);
        $this->assertNull($this->publication->forRender(Request::create('/graphql'), $this->license));
        $this->assertNotSame($draft['previewToken'], DB::table('page_drafts')->first()->preview_hash);
        $other = License::create(['user_id' => $this->owner->id, 'domain' => 'other.example.test', 'template_id' => 999]);
        try {
            $this->publication->forRender($request, $other);
            $this->fail('Expected bound token');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->travel(3)->hours();
        try {
            $this->publication->forRender($request, $this->license);
            $this->fail('Expected expired token');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->travelBack();
        $this->publication->preview($this->owner, $this->license->id, $draft['id'], 1);
        $this->expectException(HttpException::class);
        $this->publication->forRender($request, $this->license);
    }

    public function test_restore_recovers_overrides_and_layout_without_freezing_defaults(): void
    {
        $draft = $this->begin();
        $draft = $this->edit($draft, ['kind' => 'component', 'scope' => 'global', 'type' => 'Footer', 'data' => ['label' => 'Prepared footer']]);
        $draft = $this->edit($draft, ['kind' => 'layout', 'type' => 'Header', 'data' => ['phone' => 'Prepared phone']]);
        $this->publication->publish($this->owner, $this->license->id, $draft['id'], $draft['version']);
        $state = $this->publication->state($this->owner, $this->license->id, '/test');
        $baseline = collect($state['revisions'])->firstWhere('action', 'baseline');
        $this->publication->restore($this->owner, $this->license->id, $baseline['id'], $state['liveHash']);
        $this->assertSame('Published phone', $this->license->fresh()->header_data['phone']);
        $this->assertDatabaseCount('page_components', 0);
        $this->assertDatabaseHas('page_revisions', ['action' => 'restore']);
    }

    public function test_publish_rolls_back_every_write_and_preserves_draft_on_failure(): void
    {
        $draft = $this->begin();
        $draft = $this->edit($draft, ['kind' => 'component', 'type' => 'Hero', 'data' => ['title' => 'Draft']]);
        // Fail after writing the page and its block, before the layout and commit.
        $listener = function (License $license) {
            throw new \RuntimeException('Simulated storage failure');
        };
        License::saving($listener);
        try {
            $this->publication->publish($this->owner, $this->license->id, $draft['id'], $draft['version']);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated storage failure', $error->getMessage());
        } finally {
            License::flushEventListeners();
        }
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('page_components', 0);
        $this->assertDatabaseCount('page_revisions', 0);
        $this->assertDatabaseCount('page_drafts', 1);
    }

    public function test_preview_removes_credentials_but_preserves_seo_variable_contract(): void
    {
        $response = $this->publication->publicPreview([
            'site' => ['header' => ['data' => ['phone' => 'Public phone', 'apiToken' => 'secret']], 'footer' => null],
            'page' => ['componentsData' => [['type' => 'Hero', 'data' => ['title' => 'Public', 'privateEmail' => 'secret']]],
                'seo' => ['variables' => [['token' => '{title}', 'value' => 'Public']]]],
        ]);
        $this->assertSame(['phone' => 'Public phone'], $response['site']['header']['data']);
        $this->assertSame(['title' => 'Public'], $response['page']['componentsData'][0]['data']);
        $this->assertSame('{title}', $response['page']['seo']['variables'][0]['token']);
    }

    public function test_reset_in_draft_returns_current_default_without_touching_live_override(): void
    {
        $page = Page::create(['license_id' => $this->license->id, 'slug' => '/test']);
        $component = PageComponent::create([
            'page_id' => $page->id, 'license_id' => $this->license->id, 'type' => 'Hero', 'data' => ['title' => 'Live override'],
        ]);
        $draft = $this->begin();
        $draft = $this->edit($draft, ['kind' => 'reset', 'componentId' => $component->id]);
        $snapshot = json_decode(DB::table('page_drafts')->first()->snapshot, true);
        $merged = app(TemplateService::class)->getMergedPageComponents($this->license->id, $page, '/test', $snapshot['components']);
        $this->assertSame('Default title', $merged->first()->data['title']);
        $this->assertSame('Live override', $component->fresh()->data['title']);
        $this->publication->publish($this->owner, $this->license->id, $draft['id'], $draft['version']);
        $this->assertDatabaseCount('page_components', 0);
    }

    public function test_restore_rejects_concurrent_live_change_and_existing_draft(): void
    {
        $draft = $this->begin();
        $this->publication->publish($this->owner, $this->license->id, $draft['id'], $draft['version']);
        $state = $this->publication->state($this->owner, $this->license->id, '/test');
        $revision = $state['revisions'][0]['id'];
        $this->license->header_data = ['phone' => 'Changed after history loaded'];
        $this->license->save();
        try {
            $this->publication->restore($this->owner, $this->license->id, $revision, $state['liveHash']);
            $this->fail('Expected optimistic restore conflict');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $state = $this->publication->state($this->owner, $this->license->id, '/test');
        $this->begin();
        try {
            $this->publication->restore($this->owner, $this->license->id, $revision, $state['liveHash']);
            $this->fail('Expected existing draft protection');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('Changed after history loaded', $this->license->fresh()->header_data['phone']);
        $this->assertDatabaseCount('page_drafts', 1);
    }

    public function test_registered_http_endpoints_require_owner_and_validate_operations(): void
    {
        $base = '/growth/sites/'.$this->license->id.'/publication';
        $this->getJson($base.'?slug=/test')->assertUnauthorized();
        $this->actingAs($this->owner, 'api');
        $draft = $this->postJson($base.'/drafts', ['slug' => '/test'])->assertOk()->json();
        $this->postJson($base.'/drafts/'.$draft['id'], [
            'version' => 1, 'kind' => 'component', 'type' => 'Unknown', 'data' => ['title' => 'Invalid'],
        ])->assertUnprocessable();
        $this->postJson($base.'/drafts/'.$draft['id'], [
            'version' => 1, 'kind' => 'layout', 'type' => 'Header', 'data' => [],
        ])->assertOk()->assertJsonPath('version', 2);
        $this->postJson($base.'/drafts/'.$draft['id'].'/publish', ['version' => 1])->assertConflict();
        $this->actingAs(User::factory()->create(), 'api');
        $this->getJson($base.'?slug=/test')->assertNotFound();
        $this->postJson($base.'/drafts/'.$draft['id'].'/publish', ['version' => 2])->assertNotFound();
        $this->assertDatabaseCount('page_drafts', 1);
        $this->assertDatabaseCount('pages', 0);
    }

    public function test_draft_render_bypasses_shared_cache_and_never_replaces_public_response(): void
    {
        Schema::create('template_pages', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('slug');
        });
        Schema::create('components', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->unsignedInteger('template_id');
            $table->char('page_id', 26);
            $table->string('type');
        });
        $render = function (?string $token = null, string $slug = '/test'): array {
            $request = Request::create('/graphql');
            $request->headers->set('X-Forwarded-Host', $this->license->domain);
            if ($token) {
                $request->headers->set('X-Leget-Draft', $token);
            }
            $context = $this->createMock(GraphQLContext::class);
            $context->method('request')->willReturn($request);

            return app(RenderPage::class)(null, ['slug' => $slug], $context, $this->createMock(ResolveInfo::class));
        };
        $public = $render();
        $draft = $this->begin();
        $this->edit($draft, ['kind' => 'component', 'type' => 'Hero', 'data' => ['title' => 'Private prepared title']]);
        $preview = $render($draft['previewToken']);
        $this->assertSame('Private prepared title', $preview['page']['componentsData'][0]['data']['title']);
        $this->assertSame('Default title', $public['page']['componentsData'][0]['data']['title']);
        $this->assertSame($public, $render());
        config(['templates.999.pages./other' => [['type' => 'Hero', 'defaults' => ['title' => 'Other']]]]);
        try {
            $render($draft['previewToken'], '/other');
            $this->fail('Expected capability page isolation');
        } catch (GraphQLException $error) {
            $this->assertSame('PAGE_PREVIEW_NOT_FOUND', $error->getErrorCode());
        }
        $this->assertDatabaseCount('page_components', 0);

        // Shared layout strips reflect the prepared /actions cards in preview.
        config(['templates.999.pages./actions' => [
            ['type' => 'ActionsCards', 'defaults' => ['cards' => []]],
            ['type' => 'ActionsCardsExtra', 'defaults' => ['cards' => []]],
        ]]);
        $actionsPage = Page::create(['license_id' => $this->license->id, 'slug' => '/actions']);
        PageComponent::create([
            'page_id' => $actionsPage->id, 'license_id' => $this->license->id,
            'type' => 'ActionsCards', 'data' => ['cards' => [['id' => 'card', 'title' => 'Published offer']]],
        ]);
        $actionsDraft = $this->publication->begin($this->owner, $this->license->id, '/actions');
        $this->edit($actionsDraft, ['kind' => 'component', 'type' => 'ActionsCards', 'data' => ['cards' => [['id' => 'card', 'title' => 'Prepared offer']]]]);
        $this->assertSame('Prepared offer', $render($actionsDraft['previewToken'], '/actions')['site']['actionCards']['primary'][0]['title']);
        $this->assertSame('Published offer', $render(null, '/actions')['site']['actionCards']['primary'][0]['title']);
    }
}
