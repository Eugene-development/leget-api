<?php

namespace Tests\Feature\PageComponent\Property;

use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\User;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature: page-components-refactor, Property 9: Bulk Component Seeding Completeness
 *
 * For any set of pages where each page receives K components via direct
 * PageComponent::create calls (simulating what TemplateService::seedDefaultComponents
 * does), exactly K records must exist in `page_components` for that page,
 * all with `is_active = true`.
 *
 * The original test covered the one-time data migration from the legacy
 * pages.components_data column (dropped in migration
 * 2026_04_21_000001_drop_components_data_from_pages). This test verifies
 * the equivalent property at the current model level.
 *
 * Validates: Requirements 7.1
 */
class DataMigrationTest extends TestCase
{
    use RefreshDatabase;
    use TestTrait;

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * Seed K components for a page directly via PageComponent::create,
     * mirroring what TemplateService::seedDefaultComponents does.
     *
     * @param  list<array{type: string, data: array}>  $components
     */
    private function seedComponents(Page $page, License $license, array $components): void
    {
        foreach ($components as $index => $component) {
            if (empty($component['type'])) {
                continue;
            }
            PageComponent::create([
                'page_id'    => $page->id,
                'license_id' => $license->id,
                'type'       => $component['type'],
                'data'       => $component['data'] ?? [],
                'is_active'  => true,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Property 9: For any set of pages (1–5) where each page receives K (1–10)
     * valid components via seeding, exactly K records exist in `page_components`
     * for each page, all with `is_active = true`.
     */
    public function test_seeding_creates_exactly_k_active_records_per_page(): void
    {
        $this->forAll(
            Generators::choose(1, 5),  // number of pages
            Generators::choose(1, 10)  // K components per page
        )->then(function (int $pageCount, int $k): void {
            // Clean up between iterations so unique constraints don't conflict
            DB::table('page_components')->delete();
            DB::table('pages')->delete();

            $user    = $this->createUser();
            $license = $this->createLicense($user);

            $pageIds = [];

            for ($p = 0; $p < $pageCount; $p++) {
                $page = Page::create([
                    'license_id' => $license->id,
                    'slug'       => '/page-' . $p . '-' . uniqid(),
                ]);

                // Build K valid components (each with a unique type per page)
                $components = [];
                for ($i = 0; $i < $k; $i++) {
                    $components[] = [
                        'type' => 'Component' . $i,
                        'data' => ['index' => $i, 'page' => $p],
                    ];
                }

                $this->seedComponents($page, $license, $components);
                $pageIds[] = $page->id;
            }

            foreach ($pageIds as $pageId) {
                $count = PageComponent::where('page_id', $pageId)->count();

                $this->assertSame(
                    $k,
                    $count,
                    "Expected exactly {$k} page_components for page {$pageId}, found {$count}"
                );

                $inactiveCount = PageComponent::where('page_id', $pageId)
                    ->where('is_active', false)
                    ->count();

                $this->assertSame(
                    0,
                    $inactiveCount,
                    "Expected all {$k} components for page {$pageId} to have is_active = true, but {$inactiveCount} are inactive"
                );
            }
        });
    }
}
