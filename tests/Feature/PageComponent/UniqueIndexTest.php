<?php

namespace Tests\Feature\PageComponent;

use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies that the unique index (page_id, type) on page_components
 * prevents duplicate entries for the same page and component type.
 *
 * Validates: Requirements 1.2
 */
class UniqueIndexTest extends TestCase
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
            'license_id'      => $license->id,
            'slug'            => '/',
        ]);
    }

    /**
     * Inserting a PageComponent with a duplicate (page_id, type) pair
     * must throw a database exception due to the unique index.
     */
    public function test_duplicate_page_id_and_type_throws_exception(): void
    {
        $user    = $this->createUser();
        $license = $this->createLicense($user);
        $page    = $this->createPage($license);

        // First insert — should succeed
        PageComponent::create([
            'page_id'    => $page->id,
            'license_id' => $license->id,
            'type'       => 'Hero',
            'data'       => ['title' => 'Welcome'],
            'is_active'  => true,
        ]);

        // Second insert with the same (page_id, type) — must throw
        $this->expectException(QueryException::class);

        PageComponent::create([
            'page_id'    => $page->id,
            'license_id' => $license->id,
            'type'       => 'Hero',
            'data'       => ['title' => 'Duplicate'],
            'is_active'  => true,
        ]);
    }

    /**
     * The same type on a different page should be allowed — the unique
     * constraint is scoped to (page_id, type), not just type.
     */
    public function test_same_type_on_different_pages_is_allowed(): void
    {
        $user    = $this->createUser();
        $license = $this->createLicense($user);

        $page1 = Page::create([
            'license_id'      => $license->id,
            'slug'            => '/page-1',
        ]);

        $page2 = Page::create([
            'license_id'      => $license->id,
            'slug'            => '/page-2',
        ]);

        PageComponent::create([
            'page_id'    => $page1->id,
            'license_id' => $license->id,
            'type'       => 'Hero',
            'data'       => ['title' => 'Page 1 Hero'],
            'is_active'  => true,
        ]);

        // Same type, different page — must not throw
        PageComponent::create([
            'page_id'    => $page2->id,
            'license_id' => $license->id,
            'type'       => 'Hero',
            'data'       => ['title' => 'Page 2 Hero'],
            'is_active'  => true,
        ]);

        $this->assertSame(1, PageComponent::where('page_id', $page1->id)->count());
        $this->assertSame(1, PageComponent::where('page_id', $page2->id)->count());
    }
}
