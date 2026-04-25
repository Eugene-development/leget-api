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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature: page-components-refactor, Property 2: ULID Generation
 *
 * For any set of valid attributes for PageComponent, the created record
 * must have an `id` that is a 26-character string from the ULID alphabet
 * [0-9A-HJKMNP-TV-Z].
 *
 * Validates: Requirements 2.1, 2.2
 */
class UlidGenerationTest extends TestCase
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

    private function createPage(License $license, string $slug): Page
    {
        return Page::create([
            'license_id' => $license->id,
            'slug'       => $slug,
        ]);
    }

    /**
     * Property 2: For any set of valid PageComponent attributes, the created
     * record must have an `id` that is exactly 26 characters and matches the
     * ULID alphabet [0-9A-HJKMNP-TV-Z].
     */
    public function test_created_page_component_has_ulid_id(): void
    {
        $this->forAll(
            Generators::suchThat(
                fn (string $s) => strlen($s) > 0,
                Generators::string()
            )
        )->then(function (string $componentType): void {
            $user    = $this->createUser();
            $license = $this->createLicense($user);
            $page    = $this->createPage($license, '/test-' . uniqid());

            $component = PageComponent::create([
                'page_id'    => $page->id,
                'license_id' => $license->id,
                'type'       => $componentType,
                'data'       => [],
                'is_active'  => true,
            ]);

            $id = $component->id;

            $this->assertSame(
                26,
                strlen($id),
                "Expected ULID to be exactly 26 characters, got " . strlen($id) . " for id: {$id}"
            );

            // Laravel's HasUlids stores ULIDs in lowercase (strtolower applied).
            // The ULID alphabet [0-9A-HJKMNP-TV-Z] in lowercase is [0-9a-hjkmnp-tv-z].
            $this->assertMatchesRegularExpression(
                '/^[0-9a-hjkmnp-tv-z]{26}$/i',
                $id,
                "Expected ULID to match alphabet [0-9A-HJKMNP-TV-Z] (case-insensitive), got: {$id}"
            );
        });
    }
}
