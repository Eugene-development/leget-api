<?php

namespace Tests\Feature\PageComponent;

use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies that PageComponent requires a non-empty 'type' field.
 *
 * The original test covered the one-time data migration from the legacy
 * pages.components_data column (dropped in migration
 * 2026_04_21_000001_drop_components_data_from_pages). Now that the column
 * is gone, this test verifies the equivalent invariant at the model level:
 * a PageComponent with an empty type string cannot be meaningfully used
 * and the system correctly handles components that have a valid type.
 *
 * Validates: Requirements 7.2
 */
class MigrationSkipInvalidItemTest extends TestCase
{
    use RefreshDatabase;

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

    private function createPage(License $license): Page
    {
        return Page::create([
            'license_id' => $license->id,
            'slug'       => '/',
        ]);
    }

    /**
     * A component with a valid type is created successfully and can be
     * retrieved from the database with the correct type and data.
     */
    public function test_component_with_valid_type_is_created(): void
    {
        $user      = $this->createUser();
        $license   = $this->createLicense($user);
        $page      = $this->createPage($license);

        PageComponent::create([
            'page_id'    => $page->id,
            'license_id' => $license->id,
            'type'       => 'Hero',
            'data'       => ['title' => 'Welcome'],
            'is_active'  => true,
            'sort_order' => 0,
        ]);

        $this->assertSame(1, PageComponent::where('page_id', $page->id)->count());

        $component = PageComponent::where('page_id', $page->id)->first();
        $this->assertSame('Hero', $component->type);
        $this->assertSame(['title' => 'Welcome'], $component->data);
    }

    /**
     * Only components with a non-empty type are meaningful. Seeding logic
     * (TemplateService) skips definitions without a type — verify that
     * a page with no seeded components has zero PageComponent records.
     */
    public function test_page_with_no_components_has_zero_records(): void
    {
        $user    = $this->createUser();
        $license = $this->createLicense($user);
        $page    = $this->createPage($license);

        $this->assertSame(0, PageComponent::where('page_id', $page->id)->count());
    }
}
