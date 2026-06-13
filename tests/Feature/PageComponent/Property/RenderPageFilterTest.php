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
 * Feature: page-components-refactor, Property 6: RenderPage Active Filter
 *
 * For any page with an arbitrary set of N active (is_active = true) and
 * M inactive (is_active = false) components, the renderPage query must
 * return exactly N elements in componentsData, each corresponding to an
 * active component.
 *
 * Validates: Requirements 5.1
 */
class RenderPageFilterTest extends TestCase
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
     * Property 6: For any page with N (0–10) active and M (0–10) inactive
     * components, renderPage returns exactly N elements in componentsData,
     * each corresponding to an active component.
     */
    public function test_render_page_returns_only_active_components(): void
    {
        $this->forAll(
            Generators::choose(0, 10),
            Generators::choose(0, 10)
        )->then(function (int $n, int $m): void {
            $user    = $this->createUser();
            $license = $this->createLicense($user);
            $page    = $this->createPage($license);

            $activeTypes = [];

            // Create N active components
            for ($i = 0; $i < $n; $i++) {
                $type = 'ActiveComponent' . $i;
                $activeTypes[] = $type;

                PageComponent::create([
                    'page_id'    => $page->id,
                    'license_id' => $license->id,
                    'type'       => $type,
                    'data'       => ['index' => $i, 'active' => true],
                    'is_active'  => true,
                    'sort_order' => $i,
                ]);
            }

            // Create M inactive components
            for ($j = 0; $j < $m; $j++) {
                PageComponent::create([
                    'page_id'    => $page->id,
                    'license_id' => $license->id,
                    'type'       => 'InactiveComponent' . $j,
                    'data'       => ['index' => $j, 'active' => false],
                    'is_active'  => false,
                    'sort_order' => $n + $j,
                ]);
            }

            $context = $this->createContext($license->domain);
            $result  = ($this->resolver)(null, ['slug' => $page->slug], $context, $this->createResolveInfo());

            $componentsData = $result['page']['componentsData'];

            // Assert exactly N elements are returned
            $this->assertCount(
                $n,
                $componentsData,
                "Expected {$n} active components in componentsData, got " . count($componentsData) . " (N={$n}, M={$m})"
            );

            // Assert all returned elements correspond to active components
            $returnedTypes = array_column($componentsData, 'type');
            foreach ($returnedTypes as $returnedType) {
                $this->assertContains(
                    $returnedType,
                    $activeTypes,
                    "Returned component type '{$returnedType}' is not in the active components list"
                );
            }
        });
    }
}
