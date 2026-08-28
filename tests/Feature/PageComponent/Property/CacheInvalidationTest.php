<?php

namespace Tests\Feature\PageComponent\Property;

use App\GraphQL\Mutations\DeletePageComponent;
use App\GraphQL\Mutations\TogglePageComponent;
use App\GraphQL\Mutations\UpsertPageComponent;
use App\GraphQL\Queries\RenderPage;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\User;
use Eris\Generators;
use Eris\TestTrait;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

/**
 * Feature: page-components-refactor, Property 8: Cache Invalidation
 *
 * For any of the three mutations (upsertPageComponent, togglePageComponent,
 * deletePageComponent), after a successful mutation, the cache for tag
 * `license:{license_id}` must be flushed: a subsequent renderPage request
 * must hit the database, not return stale data.
 *
 * Validates: Requirements 5.4, 6.1
 */
class CacheInvalidationTest extends TestCase
{
    use RefreshDatabase;
    use TestTrait;

    private RenderPage $renderPageResolver;

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

    protected function setUp(): void
    {
        parent::setUp();

        // Use the array cache driver — it supports tagging
        config(['cache.default' => 'array']);

        $this->renderPageResolver = app(RenderPage::class);

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

    private function createUser(): User
    {
        return User::create([
            'name'     => 'Test User',
            'email'    => 'test-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    private function createLicense(User $user): License
    {
        return License::create([
            'user_id'          => $user->id,
            'domain'           => 'test-' . uniqid() . '.example.com',
            'name'             => 'Test Site',
            'meta_description' => 'A test site',
            'is_active'        => true,
            'status'           => 'active',
        ]);
    }

    private function createPage(License $license): Page
    {
        return Page::create([
            'license_id' => $license->id,
            'slug'       => '/test-' . uniqid(),
        ]);
    }

    private function createComponent(Page $page, License $license, string $type = 'Hero'): PageComponent
    {
        return PageComponent::create([
            'page_id'    => $page->id,
            'license_id' => $license->id,
            'type'       => $type,
            'data'       => ['title' => 'Original'],
            'is_active'  => true,
            'sort_order' => 0,
        ]);
    }

    private function makeOwnerContext(User $user): GraphQLContext
    {
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn($user);

        return $context;
    }

    private function makeRenderContext(string $domain): GraphQLContext
    {
        $request = Request::create('/graphql', 'POST');
        $request->headers->set('X-Forwarded-Host', $domain);

        $context = $this->createMock(GraphQLContext::class);
        $context->method('request')->willReturn($request);

        return $context;
    }

    private function createResolveInfo(): ResolveInfo
    {
        return $this->createMock(ResolveInfo::class);
    }

    /**
     * Helper: call renderPage and return the result.
     */
    private function callRenderPage(License $license, Page $page): array
    {
        $context = $this->makeRenderContext($license->domain);

        return ($this->renderPageResolver)(
            null,
            ['slug' => $page->slug],
            $context,
            $this->createResolveInfo()
        );
    }

    /**
     * Helper: assert the cache tag for a license has no entry for the given key.
     */
    private function assertCacheFlushed(License $license, Page $page): void
    {
        $cacheKey = $this->renderCacheKey($license->id, $page->slug);
        $cached   = Cache::tags(["license:{$license->id}"])->get($cacheKey);

        $this->assertNull(
            $cached,
            "Expected cache to be flushed for tag 'license:{$license->id}' after mutation, but cache still has data."
        );
    }

    /**
     * Property 8: After upsertPageComponent, the cache for the license tag is flushed.
     * A subsequent renderPage call returns updated data from the database.
     */
    public function test_upsert_page_component_invalidates_cache(): void
    {
        $this->forAll(
            Generators::elements('Hero', 'Text', 'Banner', 'Footer', 'Header')
        )->then(function (string $type): void {
            $user    = $this->createUser();
            $license = $this->createLicense($user);
            $page    = $this->createPage($license);

            // Step 1: Call renderPage to populate the cache
            $initialResult = $this->callRenderPage($license, $page);
            $this->assertEmpty(
                $initialResult['page']['componentsData'],
                'Expected empty componentsData before any component is created.'
            );

            // Step 2: Verify the cache has data
            $cacheKey = $this->renderCacheKey($license->id, $page->slug);
            $cached   = Cache::tags(["license:{$license->id}"])->get($cacheKey);
            $this->assertNotNull($cached, 'Expected cache to be populated after first renderPage call.');

            // Step 3: Call upsertPageComponent mutation
            $mutation    = app(UpsertPageComponent::class);
            $ownerCtx    = $this->makeOwnerContext($user);
            $resolveInfo = $this->createResolveInfo();

            $mutation(
                null,
                [
                    'page_id'    => $page->id,
                    'license_id' => $license->id,
                    'type'       => $type,
                    'data'       => ['title' => 'New Component'],
                ],
                $ownerCtx,
                $resolveInfo
            );

            // Step 4: Verify the cache was flushed
            $this->assertCacheFlushed($license, $page);

            // Step 5: Call renderPage again and verify it returns updated data
            $updatedResult = $this->callRenderPage($license, $page);
            $this->assertCount(
                1,
                $updatedResult['page']['componentsData'],
                "Expected 1 component in componentsData after upsert, got " . count($updatedResult['page']['componentsData'])
            );
            $this->assertSame(
                $type,
                $updatedResult['page']['componentsData'][0]['type'],
                "Expected component type '{$type}' in updated renderPage result."
            );
        });
    }

    /**
     * Property 8: After togglePageComponent, the cache for the license tag is flushed.
     * A subsequent renderPage call returns updated data from the database.
     */
    public function test_toggle_page_component_invalidates_cache(): void
    {
        $this->forAll(
            Generators::elements('Hero', 'Text', 'Banner', 'Footer', 'Header')
        )->then(function (string $type): void {
            $user      = $this->createUser();
            $license   = $this->createLicense($user);
            $page      = $this->createPage($license);
            $component = $this->createComponent($page, $license, $type);

            // Step 1: Call renderPage to populate the cache (component is active)
            $initialResult = $this->callRenderPage($license, $page);
            $this->assertCount(
                1,
                $initialResult['page']['componentsData'],
                'Expected 1 active component in initial renderPage result.'
            );

            // Step 2: Verify the cache has data
            $cacheKey = $this->renderCacheKey($license->id, $page->slug);
            $cached   = Cache::tags(["license:{$license->id}"])->get($cacheKey);
            $this->assertNotNull($cached, 'Expected cache to be populated after first renderPage call.');

            // Step 3: Call togglePageComponent to deactivate the component
            $mutation    = app(TogglePageComponent::class);
            $ownerCtx    = $this->makeOwnerContext($user);
            $resolveInfo = $this->createResolveInfo();

            $mutation(
                null,
                [
                    'id'        => $component->id,
                    'is_active' => false,
                ],
                $ownerCtx,
                $resolveInfo
            );

            // Step 4: Verify the cache was flushed
            $this->assertCacheFlushed($license, $page);

            // Step 5: Call renderPage again and verify it returns updated data (no components)
            $updatedResult = $this->callRenderPage($license, $page);
            $this->assertEmpty(
                $updatedResult['page']['componentsData'],
                'Expected empty componentsData after toggling component to inactive.'
            );
        });
    }

    /**
     * Property 8: After deletePageComponent, the cache for the license tag is flushed.
     * A subsequent renderPage call returns updated data from the database.
     */
    public function test_delete_page_component_invalidates_cache(): void
    {
        $this->forAll(
            Generators::elements('Hero', 'Text', 'Banner', 'Footer', 'Header')
        )->then(function (string $type): void {
            $user      = $this->createUser();
            $license   = $this->createLicense($user);
            $page      = $this->createPage($license);
            $component = $this->createComponent($page, $license, $type);

            // Step 1: Call renderPage to populate the cache (component is active)
            $initialResult = $this->callRenderPage($license, $page);
            $this->assertCount(
                1,
                $initialResult['page']['componentsData'],
                'Expected 1 active component in initial renderPage result.'
            );

            // Step 2: Verify the cache has data
            $cacheKey = $this->renderCacheKey($license->id, $page->slug);
            $cached   = Cache::tags(["license:{$license->id}"])->get($cacheKey);
            $this->assertNotNull($cached, 'Expected cache to be populated after first renderPage call.');

            // Step 3: Call deletePageComponent mutation
            $mutation    = app(DeletePageComponent::class);
            $ownerCtx    = $this->makeOwnerContext($user);
            $resolveInfo = $this->createResolveInfo();

            $mutation(
                null,
                [
                    'id'         => $component->id,
                    'license_id' => $license->id,
                ],
                $ownerCtx,
                $resolveInfo
            );

            // Step 4: Verify the cache was flushed
            $this->assertCacheFlushed($license, $page);

            // Step 5: Call renderPage again and verify it returns updated data (no components)
            $updatedResult = $this->callRenderPage($license, $page);
            $this->assertEmpty(
                $updatedResult['page']['componentsData'],
                'Expected empty componentsData after deleting the component.'
            );
        });
    }
}
