<?php

namespace Tests\Unit\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\GraphQL\Queries\RenderPage;
use App\Models\Category;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\Rubric;
use App\Models\User;
use App\Services\TemplateService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

class RenderPageTest extends TestCase
{
    use RefreshDatabase;

    private RenderPage $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(RenderPage::class);

        // Create licenses table (migrations live in leget-db, not leget-api)
        if (! Schema::hasTable('licenses')) {
            Schema::create('licenses', function (Blueprint $table) {
                $table->string('id', 26)->primary();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('domain')->unique();
                $table->string('name')->nullable();
                $table->text('meta_description')->nullable();
                $table->unsignedInteger('template_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pages')) {
            Schema::create('pages', function (Blueprint $table) {
                $table->id();
                $table->string('license_id', 26);
                $table->string('slug');
                $table->string('seo_title')->nullable();
                $table->text('seo_description')->nullable();
                $table->text('seo_keywords')->nullable();
                $table->timestamps();

                $table->foreign('license_id')
                    ->references('id')
                    ->on('licenses')
                    ->onDelete('cascade');

                $table->unique(['license_id', 'slug']);
            });
        }

        if (! Schema::hasTable('rubrics')) {
            Schema::create('rubrics', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('key')->unique();
                $table->boolean('is_active')->default(true);
                $table->string('value');
                $table->string('slug')->unique();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('key')->unique();
                $table->ulid('rubric_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_enabled')->default(true);
                $table->string('value');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('mebel_projects')) {
            Schema::create('mebel_projects', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('category_id');
                $table->ulid('license_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    private function createContext(array $headers = []): GraphQLContext
    {
        $request = Request::create('/graphql', 'POST');

        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }

        $context = $this->createMock(GraphQLContext::class);
        $context->method('request')->willReturn($request);

        return $context;
    }

    private function createResolveInfo(): ResolveInfo
    {
        return $this->createMock(ResolveInfo::class);
    }

    private function createLicense(array $attributes = []): License
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);

        return License::create(array_merge([
            'user_id' => $user->id,
            'domain' => 'example.com',
            'name' => 'Test Site',
            'meta_description' => 'A test site',
            'is_active' => true,
            'status' => 'active',
        ], $attributes));
    }

    public function test_resolves_domain_from_x_forwarded_host_header(): void
    {
        $license = $this->createLicense(['domain' => 'forwarded.example.com']);
        Page::create([
            'license_id' => $license->id,
            'slug' => '/',
        ]);

        $context = $this->createContext(['X-Forwarded-Host' => 'forwarded.example.com']);
        $result = ($this->resolver)(null, ['slug' => '/'], $context, $this->createResolveInfo());

        $this->assertSame('Test Site', $result['site']['name']);
    }

    public function test_falls_back_to_host_header_when_no_x_forwarded_host(): void
    {
        $license = $this->createLicense(['domain' => 'localhost']);
        Page::create([
            'license_id' => $license->id,
            'slug' => '/',
        ]);

        $context = $this->createContext(['Host' => 'localhost']);
        $result = ($this->resolver)(null, ['slug' => '/'], $context, $this->createResolveInfo());

        $this->assertSame('Test Site', $result['site']['name']);
    }

    public function test_x_forwarded_host_takes_priority_over_host(): void
    {
        $this->createLicense(['domain' => 'wrong.example.com']);
        $license = $this->createLicense(['domain' => 'correct.example.com']);
        Page::create([
            'license_id' => $license->id,
            'slug' => '/',
        ]);

        $context = $this->createContext([
            'X-Forwarded-Host' => 'correct.example.com',
            'Host' => 'wrong.example.com',
        ]);

        $result = ($this->resolver)(null, ['slug' => '/'], $context, $this->createResolveInfo());

        $this->assertSame($license->name, $result['site']['name']);
    }

    public function test_throws_site_not_found_for_unknown_domain(): void
    {
        $context = $this->createContext(['X-Forwarded-Host' => 'unknown.example.com']);

        $this->expectException(GraphQLException::class);
        $this->expectExceptionMessage('Site not found');

        try {
            ($this->resolver)(null, ['slug' => '/'], $context, $this->createResolveInfo());
        } catch (GraphQLException $e) {
            $this->assertSame('SITE_NOT_FOUND', $e->getErrorCode());
            throw $e;
        }
    }

    public function test_throws_site_suspended_when_is_active_false(): void
    {
        $this->createLicense([
            'domain' => 'inactive.example.com',
            'is_active' => false,
            'status' => 'active',
        ]);

        $context = $this->createContext(['X-Forwarded-Host' => 'inactive.example.com']);

        $this->expectException(GraphQLException::class);
        $this->expectExceptionMessage('Site is suspended');

        try {
            ($this->resolver)(null, ['slug' => '/'], $context, $this->createResolveInfo());
        } catch (GraphQLException $e) {
            $this->assertSame('SITE_SUSPENDED', $e->getErrorCode());
            throw $e;
        }
    }

    public function test_throws_site_suspended_when_status_is_suspended(): void
    {
        $this->createLicense([
            'domain' => 'suspended.example.com',
            'is_active' => true,
            'status' => 'suspended',
        ]);

        $context = $this->createContext(['X-Forwarded-Host' => 'suspended.example.com']);

        $this->expectException(GraphQLException::class);
        $this->expectExceptionMessage('Site is suspended');

        try {
            ($this->resolver)(null, ['slug' => '/'], $context, $this->createResolveInfo());
        } catch (GraphQLException $e) {
            $this->assertSame('SITE_SUSPENDED', $e->getErrorCode());
            throw $e;
        }
    }

    public function test_throws_page_not_found_for_missing_slug(): void
    {
        $this->createLicense(['domain' => 'valid.example.com']);

        $context = $this->createContext(['X-Forwarded-Host' => 'valid.example.com']);

        $this->expectException(GraphQLException::class);
        $this->expectExceptionMessage('Page not found');

        try {
            ($this->resolver)(null, ['slug' => '/nonexistent'], $context, $this->createResolveInfo());
        } catch (GraphQLException $e) {
            $this->assertSame('PAGE_NOT_FOUND', $e->getErrorCode());
            throw $e;
        }
    }

    public function test_returns_correct_response_structure(): void
    {
        $license = $this->createLicense([
            'domain' => 'site.example.com',
            'name' => 'My Site',
            'meta_description' => 'My site description',
        ]);

        $componentsData = [
            ['type' => 'Hero', 'data' => ['title' => 'Welcome']],
            ['type' => 'Text', 'data' => ['content' => 'Hello world']],
        ];

        $page = Page::create([
            'license_id' => $license->id,
            'slug' => '/about',
        ]);

        $dbComponents = [];
        foreach ($componentsData as $i => $component) {
            $dbComponents[] = PageComponent::create([
                'page_id' => $page->id,
                'license_id' => $license->id,
                'type' => $component['type'],
                'data' => $component['data'],
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }

        $context = $this->createContext(['X-Forwarded-Host' => 'site.example.com']);
        $result = ($this->resolver)(null, ['slug' => '/about'], $context, $this->createResolveInfo());

        $this->assertArrayHasKey('site', $result);
        $this->assertArrayHasKey('page', $result);

        $this->assertSame('My Site', $result['site']['name']);
        $this->assertSame('My site description', $result['site']['metaDescription']);
        $this->assertSame((string) $license->user_id, $result['site']['ownerId']);

        $this->assertSame('/about', $result['page']['slug']);

        $expectedComponents = [
            [
                'id' => $dbComponents[0]->id,
                'type' => 'Hero',
                'data' => [
                    'title' => 'Welcome',
                    '_componentId' => $dbComponents[0]->id,
                ],
            ],
            [
                'id' => $dbComponents[1]->id,
                'type' => 'Text',
                'data' => [
                    'content' => 'Hello world',
                    '_componentId' => $dbComponents[1]->id,
                ],
            ],
        ];
        $this->assertSame($expectedComponents, $result['page']['componentsData']);
    }

    /**
     * The client decides whether to draw the editing UI, because this response is
     * cached once for every visitor and fetched without a token — the server cannot
     * answer "is this you", only "the owner is this one". Ownership of the write
     * path stays server-side (see UpsertPageComponent).
     */
    public function test_returns_license_owner_id_for_the_client_side_ownership_check(): void
    {
        $license = $this->createLicense(['domain' => 'owner.example.com']);
        Page::create([
            'license_id' => $license->id,
            'slug' => '/about',
        ]);

        $context = $this->createContext(['X-Forwarded-Host' => 'owner.example.com']);
        $result = ($this->resolver)(null, ['slug' => '/about'], $context, $this->createResolveInfo());

        $this->assertSame((string) $license->user_id, $result['site']['ownerId']);
        $this->assertNotSame('', $result['site']['ownerId']);
    }

    public function test_template_definition_order_wins_over_legacy_component_sort_order(): void
    {
        $license = $this->createLicense([
            'domain' => 'ordered.example.com',
            'template_id' => 1,
        ]);

        $page = Page::create([
            'license_id' => $license->id,
            'slug' => '/mebel',
        ]);

        // Legacy rows could retain the old default sort_order=0 after being edited.
        PageComponent::create([
            'page_id' => $page->id,
            'license_id' => $license->id,
            'type' => 'MebelProcess',
            'data' => ['title' => 'Как мы работаем'],
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $components = app(TemplateService::class)->getMergedPageComponents($license->id, $page, '/mebel');
        $types = $components->pluck('type')->all();

        $this->assertSame([
            'MebelSidebar',
            'MebelHero',
            'MebelBenefits',
            'MebelSolutions',
            'MebelProcess',
            'MebelCTA',
        ], $types);
    }

    public function test_dynamic_category_data_overrides_category_hero_defaults(): void
    {
        $license = $this->createLicense([
            'domain' => 'category.example.com',
            'template_id' => 1,
        ]);

        $rubric = Rubric::create([
            'key' => (string) Str::ulid(),
            'value' => 'Мебель',
            'slug' => 'mebel',
        ]);

        Category::create([
            'key' => (string) Str::ulid(),
            'rubric_id' => $rubric->id,
            'value' => 'Кухни',
            'slug' => 'kitchens',
            'description' => 'Кухни по индивидуальным размерам',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $context = $this->createContext(['X-Forwarded-Host' => 'category.example.com']);
        $result = ($this->resolver)(
            null,
            ['slug' => '/mebel/kitchens'],
            $context,
            $this->createResolveInfo()
        );

        $hero = collect($result['page']['componentsData'])->firstWhere('type', 'MebelCategoryHero');

        $this->assertNotNull($hero);
        $this->assertSame('Кухни', $hero['data']['title']);
        $this->assertSame('Кухни по индивидуальным размерам', $hero['data']['description']);
        $this->assertSame('kitchens', $hero['data']['categorySlug']);
    }

    public function test_static_page_returns_page_seo_metadata(): void
    {
        $license = $this->createLicense([
            'domain' => 'seo.example.com',
            'name' => 'Мебельная студия',
            'meta_description' => 'Описание сайта по умолчанию',
        ]);

        Page::create([
            'license_id' => $license->id,
            'slug' => '/about',
            'seo_title' => 'О студии — мебель на заказ',
            'seo_description' => 'Проектируем и производим мебель по индивидуальным размерам.',
            'seo_keywords' => 'мебель на заказ, дизайн мебели',
        ]);

        $context = $this->createContext(['X-Forwarded-Host' => 'seo.example.com']);
        $result = ($this->resolver)(
            null,
            ['slug' => '/about'],
            $context,
            $this->createResolveInfo()
        );

        $this->assertSame('О студии — мебель на заказ', $result['page']['seo']['title']);
        $this->assertSame(
            'Проектируем и производим мебель по индивидуальным размерам.',
            $result['page']['seo']['description']
        );
        $this->assertSame('мебель на заказ, дизайн мебели', $result['page']['seo']['keywords']);
        $this->assertFalse($result['page']['seo']['isDynamic']);
        $this->assertSame('/about', $result['page']['requestedSlug']);
    }

    public function test_dynamic_page_renders_configurable_seo_template(): void
    {
        $license = $this->createLicense([
            'domain' => 'dynamic-seo.example.com',
            'name' => 'Ателье кухни',
            'template_id' => 1,
        ]);

        Page::create([
            'license_id' => $license->id,
            'slug' => '/mebel/{category}',
            'seo_title' => '{category} на заказ — {site}',
            'seo_description' => '{category_description}. Рассчитайте стоимость в {site}.',
            'seo_keywords' => 'мебель на заказ, кухни',
        ]);

        $rubric = Rubric::create([
            'key' => (string) Str::ulid(),
            'value' => 'Мебель',
            'slug' => 'mebel',
        ]);

        Category::create([
            'key' => (string) Str::ulid(),
            'rubric_id' => $rubric->id,
            'value' => 'Кухни',
            'slug' => 'kuhni',
            'description' => 'Кухни по индивидуальным размерам',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $context = $this->createContext(['X-Forwarded-Host' => 'dynamic-seo.example.com']);
        $result = ($this->resolver)(
            null,
            ['slug' => '/mebel/kuhni'],
            $context,
            $this->createResolveInfo()
        );

        $seo = $result['page']['seo'];

        $this->assertSame('Кухни на заказ — Ателье кухни', $seo['title']);
        $this->assertSame(
            'Кухни по индивидуальным размерам. Рассчитайте стоимость в Ателье кухни.',
            $seo['description']
        );
        $this->assertSame('{category} на заказ — {site}', $seo['rawTitle']);
        $this->assertTrue($seo['isDynamic']);
        $this->assertSame('/mebel/{category}', $seo['pattern']);
        $this->assertSame('/mebel/kuhni', $result['page']['requestedSlug']);
        $this->assertContains(
            ['token' => '{category}', 'label' => 'Название категории', 'value' => 'Кухни'],
            $seo['variables']
        );
    }

    public function test_caches_response_in_redis(): void
    {
        $license = $this->createLicense(['domain' => 'cached.example.com']);
        Page::create([
            'license_id' => $license->id,
            'slug' => '/',
        ]);

        $context = $this->createContext(['X-Forwarded-Host' => 'cached.example.com']);

        // First call — cache miss, should store
        $result1 = ($this->resolver)(null, ['slug' => '/'], $context, $this->createResolveInfo());

        // Verify cache was populated
        $cached = Cache::tags(["license:{$license->id}"])->get("render:v3:{$license->id}:/");
        $this->assertNotNull($cached);
        $this->assertSame($result1, $cached);
    }

    public function test_returns_cached_response_on_cache_hit(): void
    {
        $license = $this->createLicense(['domain' => 'hit.example.com']);
        Page::create([
            'license_id' => $license->id,
            'slug' => '/',
        ]);

        $cachedResponse = [
            'site' => ['name' => 'Cached Site', 'metaDescription' => 'Cached'],
            'page' => ['slug' => '/', 'componentsData' => [['type' => 'Hero', 'data' => ['title' => 'Cached']]]],
        ];

        Cache::tags(["license:{$license->id}"])->put("render:v3:{$license->id}:/", $cachedResponse, 3600);

        $context = $this->createContext(['X-Forwarded-Host' => 'hit.example.com']);
        $result = ($this->resolver)(null, ['slug' => '/'], $context, $this->createResolveInfo());

        // Should return cached data, not DB data
        $this->assertSame('Cached Site', $result['site']['name']);
    }

    public function test_ignores_legacy_cache_after_response_contract_change(): void
    {
        $license = $this->createLicense([
            'domain' => 'legacy-cache.example.com',
            'name' => 'Current Site',
        ]);
        Page::create([
            'license_id' => $license->id,
            'slug' => '/mebel',
        ]);

        Cache::tags(["license:{$license->id}"])->put("render:{$license->id}:/mebel", [
            'site' => ['name' => 'Stale Site'],
            'page' => ['slug' => '/mebel', 'componentsData' => []],
        ], 3600);

        $context = $this->createContext(['X-Forwarded-Host' => 'legacy-cache.example.com']);
        $result = ($this->resolver)(null, ['slug' => '/mebel'], $context, $this->createResolveInfo());

        $this->assertSame('Current Site', $result['site']['name']);
        $this->assertSame('/mebel', $result['page']['requestedSlug']);
        $this->assertArrayHasKey('seo', $result['page']);
    }
}
