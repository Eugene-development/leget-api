<?php

namespace Tests\Feature\PageComponent;

use App\GraphQL\Mutations\UpsertPageComponent;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\User;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

/**
 * Verifies that a BadMethodCallException thrown during cache invalidation
 * does NOT interrupt the mutation — the PageComponent is still created/updated.
 *
 * Validates: Requirements 6.2
 */
class CacheInvalidationFallbackTest extends TestCase
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
     * When Cache::tags(...) throws BadMethodCallException (e.g. non-taggable driver),
     * the UpsertPageComponent mutation must still complete successfully and persist
     * the PageComponent to the database.
     */
    public function test_cache_bad_method_call_exception_does_not_interrupt_upsert(): void
    {
        $user    = $this->createUser();
        $license = $this->createLicense($user);
        $page    = $this->createPage($license);

        // Mock Cache::tags(...) to throw BadMethodCallException,
        // then expect Cache::flush() to be called as the fallback
        Cache::shouldReceive('tags')
            ->once()
            ->andThrow(new \BadMethodCallException('This cache store does not support tagging.'));

        Cache::shouldReceive('flush')
            ->once()
            ->andReturn(true);

        // Build a fake GraphQLContext that returns our authenticated user
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn($user);

        $resolveInfo = $this->createMock(ResolveInfo::class);

        $mutation = app(UpsertPageComponent::class);

        $result = $mutation(
            null,
            [
                'page_id'    => $page->id,
                'license_id' => $license->id,
                'type'       => 'Hero',
                'data'       => ['title' => 'Hello World'],
            ],
            $context,
            $resolveInfo
        );

        // The mutation must return a PageComponent instance
        $this->assertInstanceOf(PageComponent::class, $result);
        $this->assertSame('Hero', $result->type);
        $this->assertSame(['title' => 'Hello World'], $result->data);

        // The record must actually exist in the database
        $this->assertDatabaseHas('page_components', [
            'page_id'    => $page->id,
            'license_id' => $license->id,
            'type'       => 'Hero',
        ]);
    }
}
