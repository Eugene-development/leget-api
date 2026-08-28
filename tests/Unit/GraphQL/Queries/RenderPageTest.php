<?php

namespace Tests\Unit\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\GraphQL\Queries\RenderPage;
use App\Models\Category;
use App\Models\License;
use App\Models\MebelProject;
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
}
