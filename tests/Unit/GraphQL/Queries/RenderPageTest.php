<?php

namespace Tests\Unit\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\GraphQL\Queries\RenderPage;
use App\Models\License;
use App\Models\Page;
use App\Models\User;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
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
                $table->timestamps();

                $table->foreign('license_id')
                    ->references('id')
                    ->on('licenses')
                    ->onDelete('cascade');

                $table->unique(['license_id', 'slug']);
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

    private function createResolveInfo(): \GraphQL\Type\Definition\ResolveInfo
    {
        return $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class);
    }

    private function createLicense(array $attributes = []): License
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test-' . uniqid() . '@example.com',
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
            $dbComponents[] = \App\Models\PageComponent::create([
                'page_id'    => $page->id,
                'license_id' => $license->id,
                'type'       => $component['type'],
                'data'       => $component['data'],
                'is_active'  => true,
                'sort_order' => $i,
            ]);
        }

        $context = $this->createContext(['X-Forwarded-Host' => 'site.example.com']);
        $result = ($this->resolver)(null, ['slug' => '/about'], $context, $this->createResolveInfo());

        $this->assertArrayHasKey('site', $result);
        $this->assertArrayHasKey('page', $result);

        $this->assertSame('My Site', $result['site']['name']);
        $this->assertSame('My site description', $result['site']['metaDescription']);

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
        \App\Models\PageComponent::create([
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
        $cached = Cache::tags(["license:{$license->id}"])->get("render:{$license->id}:/");
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

        Cache::tags(["license:{$license->id}"])->put("render:{$license->id}:/", $cachedResponse, 3600);

        $context = $this->createContext(['X-Forwarded-Host' => 'hit.example.com']);
        $result = ($this->resolver)(null, ['slug' => '/'], $context, $this->createResolveInfo());

        // Should return cached data, not DB data
        $this->assertSame('Cached Site', $result['site']['name']);
    }
}
