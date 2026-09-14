<?php

namespace Tests\Unit\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\GraphQL\Mutations\ToggleCategory;
use App\GraphQL\Queries\RenderPage;
use App\Models\Category;
use App\Models\Component;
use App\Models\ComponentVariant;
use App\Models\License;
use App\Models\MebelProject;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\Rubric;
use App\Models\TemplatePage;
use App\Models\User;
use App\Services\CatalogVisibility;
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

        Schema::create('template_pages', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->unsignedInteger('template_id');
            $table->string('slug');
            $table->integer('page_number');
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['template_id', 'slug']);
            $table->unique(['template_id', 'page_number']);
        });

        Schema::create('components', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->unsignedInteger('template_id');
            $table->char('page_id', 26);
            $table->string('type');
            $table->integer('component_number');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['template_id', 'page_id', 'type']);
            $table->unique(['template_id', 'page_id', 'component_number']);
        });

        Schema::create('component_variants', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('component_id', 26);
            $table->integer('version');
            $table->string('name')->nullable();
            $table->string('article')->unique();
            $table->string('status', 16)->default('draft');
            $table->timestamps();
            $table->unique(['component_id', 'version']);
        });

        // Create licenses table (migrations live in leget-db, not leget-api)
        if (! Schema::hasTable('licenses')) {
            Schema::create('licenses', function (Blueprint $table) {
                $table->string('id', 26)->primary();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('domain')->unique();
                $table->string('name')->nullable();
                $table->text('meta_description')->nullable();
                $table->json('catalog_settings')->nullable();
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

        Schema::create('catalog_brands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('category_id')->constrained('categories');
            $table->string('slug');
            $table->string('value');
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['category_id', 'slug']);
        });

        if (! Schema::hasTable('mebel_projects')) {
            Schema::create('mebel_projects', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('key')->nullable();
                $table->ulid('category_id');
                $table->ulid('license_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('value')->nullable();
                $table->string('slug')->nullable();
                $table->text('short_description')->nullable();
                $table->date('completed_at')->nullable();
                $table->string('object_address')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Лента `/projects` подгружает кадры работ — без таблицы eager load падает.
        if (! Schema::hasTable('images')) {
            Schema::create('images', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('key')->nullable();
                $table->string('path')->nullable();
                $table->string('hash')->nullable();
                $table->string('filename')->nullable();
                $table->string('original_name')->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->ulid('parentable_id')->nullable();
                $table->string('parentable_type')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    /**
     * Ключ кэша ответа RenderPage.
     *
     * Версия берётся из самого резолвера, а не пишется литералом: literal `v3`
     * стоял здесь в шести местах и сломал тесты в тот же час, когда контракт
     * ответа изменился и версию подняли. Тест обязан следовать за константой,
     * а не дублировать её.
     */
    private function renderCacheKey(string $licenseId, string $slug): string
    {
        $version = (new \ReflectionClass(RenderPage::class))->getConstant('CACHE_VERSION');

        return "render:{$version}:{$licenseId}:{$slug}";
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
                'catalog' => null,
                'data' => [
                    'title' => 'Welcome',
                    '_componentId' => $dbComponents[0]->id,
                ],
            ],
            [
                'id' => $dbComponents[1]->id,
                'type' => 'Text',
                'catalog' => null,
                'data' => [
                    'content' => 'Hello world',
                    '_componentId' => $dbComponents[1]->id,
                ],
            ],
        ];
        $this->assertSame($expectedComponents, $result['page']['componentsData']);
    }

    public function test_initial_response_contains_catalog_for_saved_and_virtual_components(): void
    {
        $license = $this->createLicense(['template_id' => 1]);
        $page = Page::create(['license_id' => $license->id, 'slug' => '/bytovaya-tehnika']);
        $saved = PageComponent::create([
            'page_id' => $page->id,
            'license_id' => $license->id,
            'type' => 'ByttehnikaBenefits',
            'data' => ['title' => 'Saved title'],
            'is_active' => true,
        ]);
        $benefits = $this->createCatalogComponent(1, '/bytovaya-tehnika', 8, 'ByttehnikaBenefits', 2);
        $cta = $this->createCatalogComponent(1, '/bytovaya-tehnika', 8, 'ByttehnikaCTA', 3);
        // Одинаковый type на другой странице/в другом шаблоне не должен подменить артикул.
        $this->createCatalogComponent(1, '/about', 9, 'ByttehnikaBenefits', 1);
        $this->createCatalogComponent(2, '/bytovaya-tehnika', 5, 'ByttehnikaBenefits', 1);

        $result = ($this->resolver)(null, ['slug' => '/bytovaya-tehnika'],
            $this->createContext(['X-Forwarded-Host' => $license->domain]), $this->createResolveInfo());
        $blocks = collect($result['page']['componentsData'])->keyBy('type');

        $this->assertSame($benefits, $blocks['ByttehnikaBenefits']['catalog']);
        $this->assertSame($cta, $blocks['ByttehnikaCTA']['catalog']);
        $this->assertSame((string) $saved->id, $blocks['ByttehnikaBenefits']['id']);
        $this->assertNull($blocks['ByttehnikaCTA']['id']);
        $this->assertNull($blocks['ByttehnikaSidebar']['catalog']);
        $this->assertSame('Saved title', $blocks['ByttehnikaBenefits']['data']['title']);
        $this->assertArrayNotHasKey('catalog', $blocks['ByttehnikaBenefits']['data']);
        $this->assertSame(['title' => 'Saved title'], $saved->fresh()->data);

        $cached = Cache::tags(["license:{$license->id}"])->get($this->renderCacheKey($license->id, '/bytovaya-tehnika'));
        $this->assertSame($result, $cached);
    }

    public function test_dynamic_brand_uses_template_slug_for_catalog_articles(): void
    {
        $license = $this->createLicense(['template_id' => 1]);
        $this->createBrand(['slug' => 'bosch', 'value' => 'Bosch']);
        $expected = $this->createCatalogComponent(1, '/bytovaya-tehnika/{brand}', 28, 'ByttehnikaBrandHero', 1);

        $result = ($this->resolver)(null, ['slug' => '/bytovaya-tehnika/bosch'],
            $this->createContext(['X-Forwarded-Host' => $license->domain]), $this->createResolveInfo());

        $hero = collect($result['page']['componentsData'])->firstWhere('type', 'ByttehnikaBrandHero');
        $this->assertSame($expected, $hero['catalog']);
    }

    public function test_saved_order_is_rendered_for_guests_and_preserves_dynamic_data(): void
    {
        Schema::table('pages', fn (Blueprint $table) => $table->json('component_order')->nullable());
        $license = $this->createLicense(['template_id' => 1]);
        $this->createBrand(['slug' => 'bosch', 'value' => 'Bosch']);
        $expected = $this->createCatalogComponent(1, '/bytovaya-tehnika/{brand}', 28, 'ByttehnikaBrandHero', 1);
        $context = $this->createContext(['X-Forwarded-Host' => $license->domain]);
        $before = ($this->resolver)(null, ['slug' => '/bytovaya-tehnika/bosch'], $context, $this->createResolveInfo());
        $types = array_values(array_filter(array_column($before['page']['componentsData'], 'type'), fn ($type) => $type !== 'Footer'));
        $ownerContext = $this->createMock(GraphQLContext::class);
        $ownerContext->method('user')->willReturn(User::findOrFail($license->user_id));
        $order = app(\App\GraphQL\Mutations\MovePageComponent::class)(null, [
            'license_id' => $license->id, 'page_id' => 'slug:/bytovaya-tehnika/bosch',
            'type' => $types[1], 'direction' => 'up',
        ], $ownerContext, $this->createResolveInfo());
        $after = ($this->resolver)(null, ['slug' => '/bytovaya-tehnika/bosch'], $context, $this->createResolveInfo());
        $this->assertSame($order, array_values(array_filter(array_column($after['page']['componentsData'], 'type'), fn ($type) => $type !== 'Footer')));
        $hero = collect($after['page']['componentsData'])->firstWhere('type', 'ByttehnikaBrandHero');
        $this->assertSame('Bosch', $hero['data']['title']);
        $this->assertSame($expected, $hero['catalog']);
        $this->assertDatabaseCount('page_components', 0);
    }

    private function createCatalogComponent(int $templateId, string $slug, int $pageNumber, string $type, int $number): array
    {
        $page = TemplatePage::firstOrCreate(
            ['template_id' => $templateId, 'slug' => $slug],
            ['page_number' => $pageNumber],
        );
        $component = Component::create([
            'template_id' => $templateId,
            'page_id' => $page->id,
            'type' => $type,
            'component_number' => $number,
        ]);
        $article = "{$templateId}.{$pageNumber}.{$number}";
        $variants = [];
        foreach ([1, 2] as $version) {
            ComponentVariant::create([
                'component_id' => $component->id,
                'version' => $version,
                'article' => "{$article}.{$version}",
                'status' => $version === 1 ? 'legacy' : 'active',
            ]);
            $variants[] = ['version' => $version, 'article' => "{$article}.{$version}"];
        }

        return ['article' => $article, 'variants' => $variants];
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

    /**
     * Бренды сайдбара бытовой техники — тот же справочник, что категории
     * мебели: строки `categories` рубрики «bytovaya-tehnika». Справочник
     * побеждает статику блока, а выключенный бренд едет в выдачу со своим
     * флагом — прячет его фронт, иначе владелец сайта не смог бы вернуть
     * пункт обратно.
     */
    public function test_byttehnika_sidebar_brands_come_from_the_rubric_directory(): void
    {
        $license = $this->createLicense([
            'domain' => 'brands.example.com',
            'template_id' => 1,
        ]);

        $this->createBrand(['value' => 'Miele', 'slug' => 'miele', 'sort_order' => 10]);
        $this->createBrand([
            'value' => 'Whirlpool',
            'slug' => 'whirlpool',
            'sort_order' => 20,
            'is_enabled' => false,
        ]);

        $context = $this->createContext(['X-Forwarded-Host' => 'brands.example.com']);
        $result = ($this->resolver)(
            null,
            ['slug' => '/bytovaya-tehnika'],
            $context,
            $this->createResolveInfo()
        );

        $sidebar = collect($result['page']['componentsData'])->firstWhere('type', 'ByttehnikaSidebar');

        $this->assertNotNull($sidebar);
        $this->assertSame(['Miele', 'Whirlpool'], array_column($sidebar['data']['brands'], 'value'));
        $this->assertTrue($sidebar['data']['brands'][0]['is_enabled']);
        $this->assertFalse($sidebar['data']['brands'][1]['is_enabled']);
        $this->assertNotEmpty($sidebar['data']['brands'][0]['id']);
        $cards = collect($result['page']['componentsData'])->firstWhere('type', 'ByttehnikaBrands');
        $this->assertNotNull($cards);
        $this->assertSame($sidebar['data']['brands'], $cards['data']['brands']);
    }

    /** Пустой справочник не возвращает ссылки из старых defaults. */
    public function test_byttehnika_sidebar_is_empty_without_directory(): void
    {
        $license = $this->createLicense([
            'domain' => 'no-brands.example.com',
            'template_id' => 1,
        ]);

        $context = $this->createContext(['X-Forwarded-Host' => 'no-brands.example.com']);
        $result = ($this->resolver)(
            null,
            ['slug' => '/bytovaya-tehnika'],
            $context,
            $this->createResolveInfo()
        );

        $sidebar = collect($result['page']['componentsData'])->firstWhere('type', 'ByttehnikaSidebar');

        $this->assertNotNull($sidebar);
        $this->assertSame([], $sidebar['data']['brands']);
        $cards = collect($result['page']['componentsData'])->firstWhere('type', 'ByttehnikaBrands');
        $this->assertNotNull($cards);
        $this->assertSame([], $cards['data']['brands']);
    }

    public function test_brand_cards_replace_saved_list_and_follow_site_visibility(): void
    {
        $license = $this->createLicense(['template_id' => 1]);
        $page = Page::create(['license_id' => $license->id, 'slug' => '/bytovaya-tehnika']);
        PageComponent::create([
            'license_id' => $license->id,
            'page_id' => $page->id,
            'type' => 'ByttehnikaBrands',
            'is_active' => true,
            'sort_order' => 2,
            'data' => ['title' => 'Наши бренды', 'brands' => [['title' => 'Старый бренд', 'slug' => 'old-brand']]],
        ]);
        for ($i = 14; $i >= 1; $i--) {
            $this->createBrand(['value' => "Brand {$i}", 'slug' => "brand-{$i}", 'sort_order' => $i]);
        }
        $hidden = Category::where('slug', 'brand-1')->firstOrFail();
        $license->catalog_settings = ['categories' => [$hidden->id => false]];
        $license->save();

        $result = ($this->resolver)(null, ['slug' => '/bytovaya-tehnika'], $this->createContext([
            'X-Forwarded-Host' => $license->domain,
        ]), $this->createResolveInfo());
        $components = collect($result['page']['componentsData']);
        $sidebar = $components->firstWhere('type', 'ByttehnikaSidebar')['data']['brands'];
        $cards = $components->firstWhere('type', 'ByttehnikaBrands')['data'];

        $this->assertSame('Наши бренды', $cards['title']);
        $this->assertSame($sidebar, $cards['brands']);
        $this->assertCount(14, $cards['brands']);
        $this->assertSame(array_map(fn ($i) => "brand-{$i}", range(1, 14)), array_column($cards['brands'], 'slug'));
        $this->assertFalse($cards['brands'][0]['is_enabled']);
        $this->assertCount(13, array_filter($cards['brands'], fn ($brand) => $brand['is_enabled']));
    }

    public function test_all_six_catalog_sidebars_have_site_scoped_visibility(): void
    {
        $first = $this->createLicense(['domain' => 'first.example.com', 'template_id' => 1]);
        $second = $this->createLicense(['domain' => 'second.example.com', 'template_id' => 1]);
        // Separate sites of the SAME owner must also remain independent.
        $second->user_id = $first->user_id;
        $second->save();

        foreach (CatalogVisibility::SIDEBARS as $type => [$rubricSlug, $itemsKey]) {
            $rubric = Rubric::create([
                'key' => (string) Str::ulid(), 'slug' => $rubricSlug, 'value' => $rubricSlug,
            ]);
            $entry = Category::create([
                'key' => (string) Str::ulid(), 'rubric_id' => $rubric->id,
                'value' => 'Entry', 'slug' => $rubricSlug.'-entry', 'is_enabled' => true,
            ]);
            $ownerContext = $this->createContext();
            $ownerContext->method('user')->willReturn($first->user);

            // Prime both caches, then mutate only the first site.
            foreach ([$first, $second] as $site) {
                ($this->resolver)(null, ['slug' => '/'.$rubricSlug],
                    $this->createContext(['Host' => $site->domain]), $this->createResolveInfo());
            }
            app(ToggleCategory::class)(null, [
                'id' => $entry->id, 'license_id' => $first->id, 'is_enabled' => false,
            ], $ownerContext, $this->createResolveInfo());

            foreach ([$first, $second] as $site) {
                $result = ($this->resolver)(null, ['slug' => '/'.$rubricSlug],
                    $this->createContext(['Host' => $site->domain]), $this->createResolveInfo());
                $sidebar = collect($result['page']['componentsData'])->firstWhere('type', $type);
                $this->assertSame($entry->id, $sidebar['data'][$itemsKey][0]['id']);
                $this->assertSame($site->id === $second->id, $sidebar['data'][$itemsKey][0]['is_enabled']);
                if ($type === 'ByttehnikaSidebar') {
                    $cards = collect($result['page']['componentsData'])->firstWhere('type', 'ByttehnikaBrands');
                    $this->assertSame($sidebar['data']['brands'], $cards['data']['brands']);
                }
            }
            $this->assertTrue($entry->fresh()->is_enabled);
        }
        $this->assertCount(6, $first->fresh()->catalog_settings['categories']);
    }

    public function test_cached_brand_url_is_blocked_only_on_the_site_that_disabled_it(): void
    {
        $first = $this->createLicense(['domain' => 'off.example.com', 'template_id' => 1]);
        $second = $this->createLicense(['domain' => 'on.example.com', 'template_id' => 1]);
        $brand = $this->createBrand();
        $render = fn (License $site) => ($this->resolver)(null, ['slug' => '/bytovaya-tehnika/bosch'],
            $this->createContext(['Host' => $site->domain]), $this->createResolveInfo());
        $render($first);
        $render($second);
        $context = $this->createContext();
        $context->method('user')->willReturn($first->user);
        app(ToggleCategory::class)(null, [
            'id' => $brand->id, 'license_id' => $first->id, 'is_enabled' => false,
        ], $context, $this->createResolveInfo());
        $this->assertSame('/bytovaya-tehnika/bosch', $render($second)['page']['requestedSlug']);
        $this->expectException(GraphQLException::class);
        $render($first);
    }

    public function test_saved_concrete_brand_page_does_not_bypass_visibility(): void
    {
        $license = $this->createLicense(['template_id' => 1]);
        $brand = $this->createBrand();
        $license->catalog_settings = ['categories' => [$brand->id => false]];
        $license->save();
        Page::create(['license_id' => $license->id, 'slug' => '/bytovaya-tehnika/bosch']);
        $this->expectException(GraphQLException::class);
        ($this->resolver)(null, ['slug' => '/bytovaya-tehnika/bosch'],
            $this->createContext(['Host' => $license->domain]), $this->createResolveInfo());
    }

    public function test_site_can_enable_a_legacy_disabled_brand(): void
    {
        $license = $this->createLicense(['template_id' => 1]);
        $brand = $this->createBrand(['is_enabled' => false]);
        $license->catalog_settings = ['categories' => [$brand->id => true]];
        $license->save();
        $result = ($this->resolver)(null, ['slug' => '/bytovaya-tehnika/bosch'],
            $this->createContext(['Host' => $license->domain]), $this->createResolveInfo());
        $this->assertSame('/bytovaya-tehnika/bosch', $result['page']['requestedSlug']);
    }

    public function test_inactive_brand_cannot_be_published_by_a_site_override(): void
    {
        $license = $this->createLicense(['template_id' => 1]);
        $brand = $this->createBrand(['is_active' => false]);
        $license->catalog_settings = ['categories' => [$brand->id => true]];
        $license->save();
        $this->expectException(GraphQLException::class);
        ($this->resolver)(null, ['slug' => '/bytovaya-tehnika/bosch'],
            $this->createContext(['Host' => $license->domain]), $this->createResolveInfo());
    }

    public function test_disabled_furniture_is_excluded_from_only_its_sites_projects_feed(): void
    {
        $first = $this->createLicense(['domain' => 'feed-off.example.com', 'template_id' => 1]);
        $second = $this->createLicense(['domain' => 'feed-on.example.com', 'template_id' => 1]);
        $category = $this->createMebelCategory();
        $this->createProject($category, ['value' => 'Project', 'slug' => 'project']);
        $first->catalog_settings = ['categories' => [$category->id => false]];
        $first->save();
        $this->assertSame(0, $this->renderProjectsPage($first, $first->domain)['hero']['total']);
        $this->assertSame(1, $this->renderProjectsPage($second, $second->domain)['hero']['total']);
        $this->expectException(GraphQLException::class);
        ($this->resolver)(null, ['slug' => '/mebel/kitchens/project'],
            $this->createContext(['Host' => $first->domain]), $this->createResolveInfo());
    }

    public function test_project_cannot_be_opened_under_another_furniture_category(): void
    {
        $license = $this->createLicense(['template_id' => 1]);
        $first = $this->createMebelCategory();
        $this->createMebelCategory(['slug' => 'other']);
        $this->createProject($first, ['value' => 'Project', 'slug' => 'project']);
        $this->expectException(GraphQLException::class);
        ($this->resolver)(null, ['slug' => '/mebel/other/project'],
            $this->createContext(['Host' => $license->domain]), $this->createResolveInfo());
    }

    /**
     * Страница бренда собирается по шаблону `/bytovaya-tehnika/{brand}`:
     * состав блоков берётся из конфига, а заголовок и описание шапки —
     * из строки справочника.
     */
    public function test_brand_slug_resolves_to_the_brand_page_template(): void
    {
        $license = $this->createLicense([
            'domain' => 'brand-page.example.com',
            'template_id' => 1,
        ]);

        $this->createBrand([
            'value' => 'Miele',
            'slug' => 'miele',
            'description' => 'Техника Miele: встраиваемые духовые шкафы и посудомоечные машины.',
        ]);

        $context = $this->createContext(['X-Forwarded-Host' => 'brand-page.example.com']);
        $result = ($this->resolver)(
            null,
            ['slug' => '/bytovaya-tehnika/miele'],
            $context,
            $this->createResolveInfo()
        );

        // Глобальные блоки (футер) дописываются после блоков страницы — сверяем
        // только состав самой страницы.
        $types = collect($result['page']['componentsData'])->pluck('type')->take(4)->all();
        $this->assertSame(
            ['ByttehnikaSidebar', 'ByttehnikaBrandHero', 'ByttehnikaBenefits', 'ByttehnikaCTA'],
            $types
        );

        $hero = collect($result['page']['componentsData'])->firstWhere('type', 'ByttehnikaBrandHero');
        $this->assertSame('Miele', $hero['data']['title']);
        $this->assertSame(
            'Техника Miele: встраиваемые духовые шкафы и посудомоечные машины.',
            $hero['data']['description']
        );
        $this->assertSame('miele', $hero['data']['brandSlug']);

        // Сайдбар подсвечивает открытый бренд.
        $sidebar = collect($result['page']['componentsData'])->firstWhere('type', 'ByttehnikaSidebar');
        $this->assertSame('miele', $sidebar['data']['activeSlug']);
    }

    /** Пустое описание в справочнике не затирает текст из defaults шаблона. */
    public function test_brand_without_description_keeps_the_template_text(): void
    {
        $license = $this->createLicense([
            'domain' => 'brand-empty.example.com',
            'template_id' => 1,
        ]);

        $this->createBrand(['value' => 'Miele', 'slug' => 'miele', 'description' => null]);

        $context = $this->createContext(['X-Forwarded-Host' => 'brand-empty.example.com']);
        $result = ($this->resolver)(
            null,
            ['slug' => '/bytovaya-tehnika/miele'],
            $context,
            $this->createResolveInfo()
        );

        $hero = collect($result['page']['componentsData'])->firstWhere('type', 'ByttehnikaBrandHero');

        $this->assertSame('Miele', $hero['data']['title']);
        $this->assertNotEmpty($hero['data']['description']);
    }

    /**
     * Slug категорий уникален на все рубрики сразу, поэтому маршрут бренда
     * обязан проверять рубрику: иначе категория мебели открылась бы страницей
     * бренда бытовой техники.
     */
    public function test_brand_route_ignores_categories_of_other_rubrics(): void
    {
        $license = $this->createLicense([
            'domain' => 'brand-foreign.example.com',
            'template_id' => 1,
        ]);

        $this->createMebelCategory(['value' => 'Кухни', 'slug' => 'kuhni']);

        $this->expectException(GraphQLException::class);

        ($this->resolver)(
            null,
            ['slug' => '/bytovaya-tehnika/kuhni'],
            $this->createContext(['X-Forwarded-Host' => 'brand-foreign.example.com']),
            $this->createResolveInfo()
        );
    }

    /** Выключенный бренд страницы не имеет — ссылка на него ведёт в 404. */
    public function test_disabled_brand_has_no_page(): void
    {
        $license = $this->createLicense([
            'domain' => 'brand-off.example.com',
            'template_id' => 1,
        ]);

        $this->createBrand(['value' => 'Miele', 'slug' => 'miele', 'is_enabled' => false]);

        $this->expectException(GraphQLException::class);

        ($this->resolver)(
            null,
            ['slug' => '/bytovaya-tehnika/miele'],
            $this->createContext(['X-Forwarded-Host' => 'brand-off.example.com']),
            $this->createResolveInfo()
        );
    }

    /**
     * Страница `/projects` делится надвое: последняя работа уходит в шапку,
     * все остальные — в ленту. Порядок задаёт дата СОЗДАНИЯ карточки.
     */
    public function test_latest_project_goes_to_hero_and_the_rest_to_the_feed(): void
    {
        $license = $this->createLicense([
            'domain' => 'feed.example.com',
            'template_id' => 1,
        ]);

        $category = $this->createMebelCategory();

        $this->createProject($category, [
            'value' => 'Заведена раньше всех',
            'slug' => 'ranshe-vseh',
            'created_at' => '2026-01-10 10:00:00',
        ]);
        $this->createProject($category, [
            'value' => 'Заведена в середине',
            'slug' => 'v-seredine',
            'created_at' => '2026-02-15 10:00:00',
        ]);
        $this->createProject($category, [
            'value' => 'Заведена последней',
            'slug' => 'poslednyaya',
            'created_at' => '2026-03-20 10:00:00',
        ]);
        $this->createProject($category, [
            'value' => 'Снята с публикации',
            'slug' => 'snyata-s-publikacii',
            'created_at' => '2026-04-01 10:00:00',
            'is_active' => false,
        ]);

        $page = $this->renderProjectsPage($license, 'feed.example.com');

        $this->assertSame('Заведена последней', $page['hero']['latest']['value']);
        $this->assertSame(
            ['Заведена в середине', 'Заведена раньше всех'],
            array_column($page['feed']['projects'], 'value'),
        );
    }

    /**
     * Счётчик у обоих блоков один и считает ВСЁ портфолио, включая работу
     * в шапке. Лента на него опирается, чтобы отличить «работ нет» от
     * «все показаны шапкой», — поэтому расхождение здесь молча ломало бы
     * пустое состояние.
     */
    public function test_both_blocks_get_the_same_total_counting_the_hero_project(): void
    {
        $license = $this->createLicense([
            'domain' => 'total.example.com',
            'template_id' => 1,
        ]);

        $category = $this->createMebelCategory();
        foreach (range(1, 3) as $i) {
            $this->createProject($category, ['value' => "Работа {$i}", 'slug' => "rabota-{$i}"]);
        }

        $page = $this->renderProjectsPage($license, 'total.example.com');

        $this->assertSame(3, $page['hero']['total']);
        $this->assertSame(3, $page['feed']['total']);
        $this->assertCount(2, $page['feed']['projects']);
    }

    /**
     * Единственная работа целиком уходит в шапку, и лента остаётся пустой при
     * `total = 1`. Различить это от «работ нет вовсе» она обязана: во втором
     * случае показывается «Проектов пока нет», а в первом — ничего, иначе
     * плашка соврала бы поверх показанной работы.
     */
    public function test_single_project_fills_the_hero_and_leaves_the_feed_empty(): void
    {
        $license = $this->createLicense([
            'domain' => 'single.example.com',
            'template_id' => 1,
        ]);

        $this->createProject($this->createMebelCategory(), [
            'value' => 'Единственная работа',
            'slug' => 'edinstvennaya',
        ]);

        $page = $this->renderProjectsPage($license, 'single.example.com');

        $this->assertSame('Единственная работа', $page['hero']['latest']['value']);
        $this->assertSame([], $page['feed']['projects']);
        $this->assertSame(1, $page['feed']['total']);
    }

    /** Портфолио пустое: шапке нечего показать, счётчик нулевой. */
    public function test_empty_portfolio_leaves_the_hero_without_a_project(): void
    {
        $license = $this->createLicense([
            'domain' => 'nothing.example.com',
            'template_id' => 1,
        ]);
        $this->createMebelCategory();

        $page = $this->renderProjectsPage($license, 'nothing.example.com');

        $this->assertNull($page['hero']['latest']);
        $this->assertSame(0, $page['hero']['total']);
        $this->assertSame(0, $page['feed']['total']);
    }

    /**
     * Проект без даты завершения из выдачи НЕ выпадает.
     *
     * Это регрессия, которая уже случилась: дата была условием показа, и у
     * тенанта с полным каталогом страница оказалась пустой — поле новое, и
     * заполнено оно ни у кого. Дата завершения — атрибут карточки, а не
     * пропуск в ленту.
     */
    public function test_projects_without_completion_date_are_kept(): void
    {
        $license = $this->createLicense([
            'domain' => 'nodate.example.com',
            'template_id' => 1,
        ]);

        $category = $this->createMebelCategory();

        $this->createProject($category, [
            'value' => 'Без даты завершения',
            'slug' => 'bez-daty',
            'completed_at' => null,
            'created_at' => '2026-03-20 10:00:00',
        ]);
        $this->createProject($category, [
            'value' => 'С датой завершения',
            'slug' => 's-datoy',
            'completed_at' => '2026-02-02',
            'created_at' => '2026-01-10 10:00:00',
        ]);

        $page = $this->renderProjectsPage($license, 'nodate.example.com');

        $this->assertSame('Без даты завершения', $page['hero']['latest']['value']);
        $this->assertNull($page['hero']['latest']['completedAt']);
        $this->assertSame('2026-02-02', $page['feed']['projects'][0]['completedAt']);
    }

    /**
     * Адрес публикуется усечённым: город и район, не дом и не квартира.
     *
     * Усечение делает выдача, а не форма. Тенант вводит полный адрес один раз
     * и не может случайно опубликовать больше, чем собирался, — а раз так,
     * поведение обязано быть закреплено тестом, иначе однажды «оптимизируют».
     * Проверяем и шапку, и ленту: усечение общее, но проходят они разными
     * ветками обогащения.
     */
    public function test_object_address_is_truncated_to_two_segments_everywhere(): void
    {
        $license = $this->createLicense([
            'domain' => 'address.example.com',
            'template_id' => 1,
        ]);

        $category = $this->createMebelCategory();

        $this->createProject($category, [
            'value' => 'Полный адрес',
            'slug' => 'polnyy-adres',
            'created_at' => '2026-03-03 10:00:00',
            'object_address' => 'Москва, Хамовники, ул. Примерная, 1, кв. 42',
        ]);
        $this->createProject($category, [
            'value' => 'Только город',
            'slug' => 'tolko-gorod',
            'created_at' => '2026-03-02 10:00:00',
            'object_address' => 'Москва',
        ]);
        $this->createProject($category, [
            'value' => 'Адреса нет',
            'slug' => 'adresa-net',
            'created_at' => '2026-03-01 10:00:00',
            'object_address' => null,
        ]);

        $page = $this->renderProjectsPage($license, 'address.example.com');

        $this->assertSame('Москва, Хамовники', $page['hero']['latest']['objectAddress']);
        $this->assertSame('Москва', $page['feed']['projects'][0]['objectAddress']);
        $this->assertNull($page['feed']['projects'][1]['objectAddress']);
    }

    /**
     * Работы отключённой рубрики в выдачу не попадают.
     *
     * Карточка ведёт на `/mebel/{category}/{project}`, а отключённую категорию
     * RenderPage не резолвит — то есть страница вела бы на 404 из собственного
     * списка.
     */
    public function test_disabled_categories_are_skipped(): void
    {
        $license = $this->createLicense([
            'domain' => 'disabled.example.com',
            'template_id' => 1,
        ]);

        $visible = $this->createMebelCategory();
        $hidden = $this->createMebelCategory([
            'value' => 'Гардеробные',
            'slug' => 'wardrobes',
            'is_enabled' => false,
        ]);

        $this->createProject($visible, ['value' => 'Видимый проект', 'slug' => 'vidimyy-proekt']);
        $this->createProject($hidden, [
            'value' => 'Проект скрытой рубрики',
            'slug' => 'proekt-skrytoy-rubriki',
        ]);

        $page = $this->renderProjectsPage($license, 'disabled.example.com');

        $this->assertSame('Видимый проект', $page['hero']['latest']['value']);
        $this->assertSame(1, $page['hero']['total']);
    }

    /** Паспорт из `meta` доезжает до выдачи списками, а не строкой. */
    public function test_maker_and_brands_come_from_meta(): void
    {
        $license = $this->createLicense([
            'domain' => 'meta.example.com',
            'template_id' => 1,
        ]);

        $this->createProject($this->createMebelCategory(), [
            'value' => 'Кухня с паспортом',
            'slug' => 'kuhnya-s-pasportom',
            'completed_at' => '2026-03-14',
            'meta' => [
                'maker' => 'Собственное производство',
                'hardware_brands' => ['Blum', 'Hettich'],
                'appliance_brands' => ['Bosch'],
            ],
        ]);

        $latest = $this->renderProjectsPage($license, 'meta.example.com')['hero']['latest'];

        $this->assertSame('Собственное производство', $latest['maker']);
        $this->assertSame(['Blum', 'Hettich'], $latest['hardwareBrands']);
        $this->assertSame(['Bosch'], $latest['applianceBrands']);
    }

    /** Проект без паспорта отдаётся пустыми списками, а не null. */
    public function test_missing_meta_becomes_empty_lists(): void
    {
        $license = $this->createLicense([
            'domain' => 'nometa.example.com',
            'template_id' => 1,
        ]);

        $this->createProject($this->createMebelCategory(), [
            'value' => 'Без паспорта',
            'slug' => 'bez-pasporta',
        ]);

        $latest = $this->renderProjectsPage($license, 'nometa.example.com')['hero']['latest'];

        $this->assertNull($latest['maker']);
        $this->assertSame([], $latest['hardwareBrands']);
        $this->assertSame([], $latest['applianceBrands']);
    }

    /**
     * Страница `/projects` целиком: шапка с последней работой и лента с
     * остальными. Возвращаем оба блока — граница между ними и есть то, что
     * эти тесты проверяют.
     *
     * @return array{hero: array<string, mixed>, feed: array<string, mixed>}
     */
    private function renderProjectsPage(License $license, string $domain): array
    {
        $result = ($this->resolver)(
            null,
            ['slug' => '/projects'],
            $this->createContext(['X-Forwarded-Host' => $domain]),
            $this->createResolveInfo()
        );

        $components = collect($result['page']['componentsData']);
        $hero = $components->firstWhere('type', 'ProjectsHero');
        $feed = $components->firstWhere('type', 'ProjectsFeed');

        $this->assertNotNull($hero, 'Блок ProjectsHero не отрендерился на /projects');
        $this->assertNotNull($feed, 'Блок ProjectsFeed не отрендерился на /projects');

        return ['hero' => $hero['data'], 'feed' => $feed['data']];
    }

    public function test_countertop_directory_and_dynamic_brand_follow_material_visibility(): void
    {
        $license = $this->createLicense(['template_id' => 1]);
        $rubric = Rubric::create(['key' => (string) Str::ulid(), 'value' => 'Столешницы', 'slug' => 'stoleshnica']);
        $material = Category::create(['key' => (string) Str::ulid(), 'rubric_id' => $rubric->id, 'value' => 'Кварцевый агломерат', 'slug' => 'kvarc', 'is_enabled' => true]);
        $brand = \App\Models\CatalogBrand::create(['category_id' => $material->id, 'value' => 'Тестовый бренд', 'slug' => 'test-brand', 'description' => 'Описание бренда']);
        \App\Models\CatalogBrand::create(['category_id' => $material->id, 'value' => 'Неактивный', 'slug' => 'inactive', 'is_active' => false]);
        $context = $this->createContext(['X-Forwarded-Host' => $license->domain]);
        $render = fn ($slug) => ($this->resolver)(null, ['slug' => $slug], $context, $this->createResolveInfo());
        $components = collect($render('/stoleshnica')['page']['componentsData']);
        $materials = $components->firstWhere('type', 'StoleshnicaSidebar')['data']['categories'];
        $cards = $components->firstWhere('type', 'StoleshnicaBrands')['data']['brands'];
        $this->assertSame($materials[0]['brands'], $cards);
        $this->assertCount(1, $cards);
        $this->assertSame('/stoleshnica/kvarc/test-brand', $cards[0]['href']);
        $dynamic = $render($cards[0]['href']);
        $this->assertSame('/stoleshnica/{material}/{brand}', $dynamic['page']['seo']['pattern']);
        $hero = collect($dynamic['page']['componentsData'])->firstWhere('type', 'StoleshnicaBrandHero')['data'];
        $this->assertSame($brand->value, $hero['title']);
        $this->assertSame($brand->description, $hero['description']);
        $sidebar = collect($dynamic['page']['componentsData'])->firstWhere('type', 'StoleshnicaSidebar')['data'];
        $this->assertSame('kvarc', $sidebar['activeSlug']);
        $this->assertSame('test-brand', $sidebar['activeBrandSlug']);
        $this->assertSame('/stoleshnica/{material}', $render('/stoleshnica/kvarc')['page']['seo']['pattern']);

        $page = Page::create(['license_id' => $license->id, 'slug' => '/stoleshnica/kvarc/test-brand']);
        PageComponent::create([
            'license_id' => $license->id, 'page_id' => $page->id, 'type' => 'StoleshnicaBrandHero',
            'data' => ['title' => 'Свой заголовок', 'description' => ''], 'is_active' => true,
        ]);
        Cache::flush();
        $savedHero = collect($render('/stoleshnica/kvarc/test-brand')['page']['componentsData'])->firstWhere('type', 'StoleshnicaBrandHero')['data'];
        $this->assertSame('Свой заголовок', $savedHero['title']);
        $this->assertSame('', $savedHero['description']);

        $license->catalog_settings = ['categories' => [$material->id => false]];
        $license->save();
        $cards = collect($render('/stoleshnica')['page']['componentsData'])->firstWhere('type', 'StoleshnicaBrands')['data']['brands'];
        $this->assertFalse($cards[0]['is_enabled']);
        $this->expectException(GraphQLException::class);
        $render('/stoleshnica/kvarc/test-brand');
    }

    public function test_countertop_brand_cannot_open_under_wrong_material_or_when_inactive(): void
    {
        $license = $this->createLicense(['template_id' => 1]);
        $rubric = Rubric::create(['key' => (string) Str::ulid(), 'value' => 'Столешницы', 'slug' => 'stoleshnica']);
        $quartz = Category::create(['key' => (string) Str::ulid(), 'rubric_id' => $rubric->id, 'value' => 'Кварц', 'slug' => 'kvarc', 'is_enabled' => true]);
        Category::create(['key' => (string) Str::ulid(), 'rubric_id' => $rubric->id, 'value' => 'Акрил', 'slug' => 'akril', 'is_enabled' => true]);
        \App\Models\CatalogBrand::create(['category_id' => $quartz->id, 'value' => 'Brand', 'slug' => 'brand']);
        \App\Models\CatalogBrand::create(['category_id' => $quartz->id, 'value' => 'Inactive', 'slug' => 'inactive', 'is_active' => false]);
        foreach (['/stoleshnica/akril/brand', '/stoleshnica/kvarc/missing', '/stoleshnica/kvarc/inactive', '/stoleshnica/kvarc/brand/extra'] as $slug) {
            // Even a saved concrete page must not bypass catalog validation.
            Page::create(['license_id' => $license->id, 'slug' => $slug]);
            try {
                ($this->resolver)(null, ['slug' => $slug], $this->createContext(['X-Forwarded-Host' => $license->domain]), $this->createResolveInfo());
                $this->fail('Invalid brand URL must fail: '.$slug);
            } catch (GraphQLException $exception) {
                $this->assertSame('Page not found', $exception->getMessage());
            }
        }
    }

    /** Бренд бытовой техники — категория рубрики «bytovaya-tehnika». */
    /**
     * Страница бренда сантехники собирается так же, как страница бренда
     * бытовой техники: состав блоков — из конфига, заголовок и описание
     * шапки — из строки справочника рубрики «santehnika».
     */
    public function test_santehnika_brand_slug_resolves_to_the_brand_page_template(): void
    {
        $license = $this->createLicense([
            'domain' => 'santehnika-brand.example.com',
            'template_id' => 1,
        ]);

        $this->createSantehnikaBrand([
            'value' => 'Omoikiri',
            'slug' => 'omoikiri',
            'description' => 'Кухонные мойки и смесители Omoikiri: гранитные и стальные модели.',
        ]);

        $context = $this->createContext(['X-Forwarded-Host' => 'santehnika-brand.example.com']);
        $result = ($this->resolver)(
            null,
            ['slug' => '/santehnika/omoikiri'],
            $context,
            $this->createResolveInfo()
        );

        // Глобальные блоки (футер) дописываются после блоков страницы — сверяем
        // только состав самой страницы.
        $types = collect($result['page']['componentsData'])->pluck('type')->take(4)->all();
        $this->assertSame(
            ['SantehnikaSidebar', 'SantehnikaBrandHero', 'SantehnikaBenefits', 'SantehnikaCTA'],
            $types
        );

        $hero = collect($result['page']['componentsData'])->firstWhere('type', 'SantehnikaBrandHero');
        $this->assertSame('Omoikiri', $hero['data']['title']);
        $this->assertSame(
            'Кухонные мойки и смесители Omoikiri: гранитные и стальные модели.',
            $hero['data']['description']
        );
        $this->assertSame('omoikiri', $hero['data']['brandSlug']);

        // Сайдбар подсвечивает открытый бренд.
        $sidebar = collect($result['page']['componentsData'])->firstWhere('type', 'SantehnikaSidebar');
        $this->assertSame('omoikiri', $sidebar['data']['activeSlug']);
    }

    /**
     * Отключённый бренд не должен открываться по прямому адресу: тумблер
     * сайдбара обязан закрывать и саму страницу.
     */
    public function test_disabled_santehnika_brand_page_is_not_found(): void
    {
        $license = $this->createLicense([
            'domain' => 'santehnika-off.example.com',
            'template_id' => 1,
        ]);

        $this->createSantehnikaBrand(['value' => 'EMAR', 'slug' => 'emar', 'is_enabled' => false]);

        $this->expectException(GraphQLException::class);

        ($this->resolver)(
            null,
            ['slug' => '/santehnika/emar'],
            $this->createContext(['X-Forwarded-Host' => 'santehnika-off.example.com']),
            $this->createResolveInfo()
        );
    }

    /**
     * Slug уникален на все рубрики сразу, поэтому маршрут бренда сантехники
     * обязан проверять рубрику — иначе бренд техники открылся бы по адресу
     * сантехники.
     */
    public function test_santehnika_brand_route_ignores_categories_of_other_rubrics(): void
    {
        $license = $this->createLicense([
            'domain' => 'santehnika-foreign.example.com',
            'template_id' => 1,
        ]);

        $this->createBrand(['value' => 'Bosch', 'slug' => 'bosch']);

        $this->expectException(GraphQLException::class);

        ($this->resolver)(
            null,
            ['slug' => '/santehnika/bosch'],
            $this->createContext(['X-Forwarded-Host' => 'santehnika-foreign.example.com']),
            $this->createResolveInfo()
        );
    }

    /** Карточки брендов на /santehnika берут список из справочника, как сайдбар. */
    public function test_santehnika_brand_cards_come_from_the_directory(): void
    {
        $license = $this->createLicense([
            'domain' => 'santehnika-cards.example.com',
            'template_id' => 1,
        ]);

        $this->createSantehnikaBrand(['value' => 'Omoikiri', 'slug' => 'omoikiri', 'sort_order' => 10]);
        $this->createSantehnikaBrand(['value' => 'Pereal', 'slug' => 'pereal', 'sort_order' => 20]);

        $result = ($this->resolver)(
            null,
            ['slug' => '/santehnika'],
            $this->createContext(['X-Forwarded-Host' => 'santehnika-cards.example.com']),
            $this->createResolveInfo()
        );

        $cards = collect($result['page']['componentsData'])->firstWhere('type', 'SantehnikaBrands');
        $this->assertSame(
            ['Omoikiri', 'Pereal'],
            collect($cards['data']['brands'])->pluck('value')->all()
        );
    }

    private function createSantehnikaBrand(array $attributes = []): Category
    {
        $rubric = Rubric::where('slug', 'santehnika')->first() ?? Rubric::create([
            'key' => (string) Str::ulid(),
            'value' => 'Сантехника',
            'slug' => 'santehnika',
        ]);

        return Category::create(array_merge([
            'key' => (string) Str::ulid(),
            'rubric_id' => $rubric->id,
            'value' => 'Omoikiri',
            'slug' => 'omoikiri',
            'is_active' => true,
            'is_enabled' => true,
        ], $attributes));
    }

    private function createBrand(array $attributes = []): Category
    {
        $rubric = Rubric::where('slug', 'bytovaya-tehnika')->first() ?? Rubric::create([
            'key' => (string) Str::ulid(),
            'value' => 'Бытовая техника',
            'slug' => 'bytovaya-tehnika',
        ]);

        return Category::create(array_merge([
            'key' => (string) Str::ulid(),
            'rubric_id' => $rubric->id,
            'value' => 'Bosch',
            'slug' => 'bosch',
            'is_active' => true,
            'is_enabled' => true,
        ], $attributes));
    }

    private function createMebelCategory(array $attributes = []): Category
    {
        $rubric = Rubric::where('slug', 'mebel')->first() ?? Rubric::create([
            'key' => (string) Str::ulid(),
            'value' => 'Мебель',
            'slug' => 'mebel',
        ]);

        return Category::create(array_merge([
            'key' => (string) Str::ulid(),
            'rubric_id' => $rubric->id,
            'value' => 'Кухни',
            'slug' => 'kitchens',
            'is_active' => true,
            'is_enabled' => true,
        ], $attributes));
    }

    /**
     * `created_at` задаётся в обход массового присвоения: его нет в `$fillable`,
     * и через `create()` он молча заменяется текущим временем — все проекты
     * оказываются созданными в одну секунду, порядок держится только на ULID,
     * и тест сортировки проверяет не то, что написано.
     */
    private function createProject(Category $category, array $attributes = []): MebelProject
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $project = MebelProject::create(array_merge([
            'key' => (string) Str::ulid(),
            'category_id' => $category->id,
            'is_active' => true,
        ], $attributes));

        if ($createdAt !== null) {
            $project->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $project;
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
        $cached = Cache::tags(["license:{$license->id}"])->get($this->renderCacheKey($license->id, '/'));
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

        Cache::tags(["license:{$license->id}"])->put($this->renderCacheKey($license->id, '/'), $cachedResponse, 3600);

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
    public function test_site_search_uses_merged_content_and_isolates_tenants(): void
    {
        config(['templates' => [1 => ['pages' => [
            '/' => [['type' => 'HeroMain', 'defaults' => ['title' => 'Кухня мечты']]],
            '/about' => [['type' => 'AboutHero', 'defaults' => ['description' => 'Уникальный дефолт']]],
            '/404' => [['type' => 'NotFound', 'defaults' => ['title' => 'Кухня ошибка']]],
        ]]]]);
        $license = $this->createLicense(['template_id' => 1]);
        $other = $this->createLicense(['template_id' => 1, 'domain' => 'other.example.com']);
        $page = Page::create(['license_id' => $license->id, 'slug' => '/about']);
        PageComponent::create(['license_id' => $license->id, 'page_id' => $page->id,
            'type' => 'AboutHero', 'data' => ['title' => 'Кухня на заказ', 'description' => '<p>Редкий фасад</p>'], 'is_active' => true]);
        $context = $this->createContext(['X-Forwarded-Host' => 'example.com']);
        $search = app(\App\GraphQL\Queries\SearchSite::class);
        $result = $search(null, ['query' => 'КУХ'], $context, $this->createResolveInfo());
        $this->assertSame(2, $result['total']);
        $this->assertSame(['/', '/about'], array_column($result['items'], 'url'));
        $this->assertSame(0, $search(null, ['query' => 'дефолт'], $context, $this->createResolveInfo())['total']);
        $this->assertSame(1, $search(null, ['query' => 'Редкий'], $context, $this->createResolveInfo())['total']);
        $otherContext = $this->createContext(['X-Forwarded-Host' => $other->domain]);
        $this->assertSame(0, $search(null, ['query' => 'Редкий'], $otherContext, $this->createResolveInfo())['total']);
        $this->assertSame(0, $search(null, ['query' => 'ку'], $context, $this->createResolveInfo())['total']);
    }

    public function test_site_search_paths_exclude_hidden_categories_and_foreign_projects(): void
    {
        config(['templates' => [1 => ['pages' => ['/mebel/{category}' => [], '/mebel/{category}/{project}' => []]]]]);
        $license = $this->createLicense(['template_id' => 1]);
        $rubric = Rubric::create(['key' => (string) Str::ulid(), 'value' => 'Мебель', 'slug' => 'mebel', 'is_active' => true]);
        $category = Category::create(['key' => (string) Str::ulid(), 'rubric_id' => $rubric->id,
            'value' => 'Кухни', 'slug' => 'kuhni', 'is_active' => true, 'is_enabled' => true]);
        foreach (['shared' => null, 'own' => $license->id, 'foreign' => 'another-license'] as $slug => $owner) {
            MebelProject::create(['category_id' => $category->id, 'license_id' => $owner,
                'slug' => $slug, 'value' => $slug, 'is_active' => true]);
        }
        $paths = app(\App\Services\SiteSearch::class)->paths($license);
        $this->assertContains('/mebel/kuhni/shared', $paths);
        $this->assertContains('/mebel/kuhni/own', $paths);
        $this->assertNotContains('/mebel/kuhni/foreign', $paths);
        $license->catalog_settings = ['categories' => [$category->id => false]];
        $this->assertSame([], app(\App\Services\SiteSearch::class)->paths($license));
    }

    public function test_site_search_rejects_suspended_sites_even_with_cached_results(): void
    {
        $this->createLicense(['status' => 'suspended']);
        $this->expectException(GraphQLException::class);
        app(\App\GraphQL\Queries\SearchSite::class)(null, ['query' => 'кухня'],
            $this->createContext(['X-Forwarded-Host' => 'example.com']), $this->createResolveInfo());
    }

    public function test_site_search_rate_limit_is_independent_for_each_client_ip(): void
    {
        config(['templates' => [1 => ['pages' => []]]]);
        $license = $this->createLicense(['template_id' => 1]);
        \Illuminate\Support\Facades\RateLimiter::hit('site-search:'.$license->id.':127.0.0.1', 60);
        for ($i = 1; $i < 60; $i++) {
            \Illuminate\Support\Facades\RateLimiter::hit('site-search:'.$license->id.':127.0.0.1', 60);
        }
        $search = app(\App\GraphQL\Queries\SearchSite::class);
        $request = Request::create('/graphql', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.2']);
        $request->headers->set('X-Forwarded-Host', 'example.com');
        $context = $this->createMock(GraphQLContext::class);
        $context->method('request')->willReturn($request);
        $this->assertSame(0, $search(null, ['query' => 'кухня'], $context, $this->createResolveInfo())['total']);
        $this->expectException(GraphQLException::class);
        $this->expectExceptionMessage('Слишком много запросов');
        $search(null, ['query' => 'кухня'], $this->createContext(['X-Forwarded-Host' => 'example.com']), $this->createResolveInfo());
    }

}
