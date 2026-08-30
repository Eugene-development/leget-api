<?php

namespace Tests\Feature\PageComponent\Property;

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
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

/**
 * Feature: page-components-refactor, Property 7: ComponentsData Format
 *
 * For any set of active PageComponents, each element of the `componentsData`
 * array in the `renderPage` response contains `id`, `type` (non-empty string),
 * `data` (array/object) and `catalog` (article metadata or null), compatible with
 * `ComponentResolver.svelte`.
 *
 * Validates: Requirements 5.2, 8.3
 */
class ComponentsDataFormatTest extends TestCase
{
    use RefreshDatabase;
    use TestTrait;

    private RenderPage $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(RenderPage::class);

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

    private function createContext(string $domain): GraphQLContext
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
     * Property 7: For any set of 1–10 active PageComponents, each element of
     * componentsData must contain `id`, `type`, `data` and `catalog`,
     * ensuring compatibility with ComponentResolver.svelte.
     */
    public function test_components_data_elements_have_type_and_data_keys(): void
    {
        $this->forAll(
            Generators::choose(1, 10)
        )->then(function (int $n): void {
            $user    = $this->createUser();
            $license = $this->createLicense($user);
            $page    = $this->createPage($license);

            // Create N active components with varied data shapes
            for ($i = 0; $i < $n; $i++) {
                PageComponent::create([
                    'page_id'    => $page->id,
                    'license_id' => $license->id,
                    'type'       => 'Component' . $i,
                    'data'       => ['index' => $i, 'value' => 'test-' . $i],
                    'is_active'  => true,
                    'sort_order' => $i,
                ]);
            }

            $context = $this->createContext($license->domain);
            $result  = ($this->resolver)(null, ['slug' => $page->slug], $context, $this->createResolveInfo());

            $componentsData = $result['page']['componentsData'];

            $this->assertCount(
                $n,
                $componentsData,
                "Expected {$n} elements in componentsData, got " . count($componentsData)
            );

            foreach ($componentsData as $index => $element) {
                // Catalog metadata stays separate from editable data.
                $keys = array_keys($element);
                sort($keys);
                $this->assertSame(
                    ['catalog', 'data', 'id', 'type'],
                    $keys,
                    "Element #{$index} must contain exactly the keys 'catalog', 'data', 'id' and 'type', got: " . implode(', ', $keys)
                );
                // These licenses have no template, so no catalog entry exists.
                $this->assertNull($element['catalog']);

                // Assert 'type' is a non-empty string
                $this->assertIsString(
                    $element['type'],
                    "Element #{$index}: 'type' must be a string"
                );
                $this->assertNotEmpty(
                    $element['type'],
                    "Element #{$index}: 'type' must be a non-empty string"
                );

                // Assert 'data' is an array
                $this->assertIsArray(
                    $element['data'],
                    "Element #{$index}: 'data' must be an array"
                );
            }
        });
    }
}
