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
 * Feature: page-components-refactor, Property 1: Cascade Delete
 *
 * For any page with an arbitrary number of components (N >= 0),
 * after deleting that page, there should be no records in
 * `page_components` with that `page_id`.
 *
 * Validates: Requirements 1.3
 */
class CascadeDeleteTest extends TestCase
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
     * Property 1: For any page with N (0–20) components, after deleting
     * the page, no PageComponent records remain for that page_id.
     */
    public function test_cascade_delete_removes_all_components(): void
    {
        $this->forAll(
            Generators::choose(0, 20)
        )->then(function (int $n): void {
            $user    = $this->createUser();
            $license = $this->createLicense($user);
            $page    = $this->createPage($license, '/test-' . uniqid());

            // Create N components for the page
            for ($i = 0; $i < $n; $i++) {
                PageComponent::create([
                    'page_id'    => $page->id,
                    'license_id' => $license->id,
                    'type'       => 'Component' . $i,
                    'data'       => ['index' => $i],
                    'is_active'  => true,
                ]);
            }

            $this->assertSame(
                $n,
                PageComponent::where('page_id', $page->id)->count(),
                "Expected {$n} components before deletion"
            );

            // Delete the page — cascade should remove all its components
            $pageId = $page->id;
            $page->delete();

            $remaining = PageComponent::where('page_id', $pageId)->count();

            $this->assertSame(
                0,
                $remaining,
                "After deleting page {$pageId} with {$n} components, expected 0 remaining but found {$remaining}"
            );
        });
    }
}
