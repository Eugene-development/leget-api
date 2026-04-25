<?php

namespace Tests\Feature\PageComponent\Property;

use App\Exceptions\GraphQLException;
use App\GraphQL\Mutations\DeletePageComponent;
use App\GraphQL\Mutations\TogglePageComponent;
use App\GraphQL\Mutations\UpsertPageComponent;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\User;
use Eris\Generators;
use Eris\TestTrait;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

/**
 * Feature: page-components-refactor, Property 5: Authorization
 *
 * For any user U and license L where U is NOT the owner of L, calling any of
 * the mutations upsertPageComponent, togglePageComponent, deletePageComponent
 * must return a GraphQL error with code AUTHORIZATION.
 *
 * Validates: Requirements 4.5
 */
class AuthorizationTest extends TestCase
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

    private function createPage(License $license): Page
    {
        return Page::create([
            'license_id' => $license->id,
            'slug'       => '/test-' . uniqid(),
        ]);
    }

    private function createComponent(Page $page, License $license): PageComponent
    {
        return PageComponent::create([
            'page_id'    => $page->id,
            'license_id' => $license->id,
            'type'       => 'Hero',
            'data'       => ['title' => 'Test'],
            'is_active'  => true,
        ]);
    }

    private function makeContext(User $user): GraphQLContext
    {
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn($user);

        return $context;
    }

    /**
     * Property 5: For any attacker user (not the license owner), calling
     * upsertPageComponent must throw a GraphQLException with code AUTHORIZATION.
     */
    public function test_upsert_page_component_throws_authorization_for_non_owner(): void
    {
        $this->forAll(
            Generators::elements('Hero', 'Text', 'Banner', 'Footer', 'Header')
        )->then(function (string $type): void {
            $owner    = $this->createUser();
            $attacker = $this->createUser();
            $license  = $this->createLicense($owner);
            $page     = $this->createPage($license);

            $context     = $this->makeContext($attacker);
            $resolveInfo = $this->createMock(ResolveInfo::class);
            $mutation    = new UpsertPageComponent();

            $exception = null;

            try {
                $mutation(
                    null,
                    [
                        'page_id'    => $page->id,
                        'license_id' => $license->id,
                        'type'       => $type,
                        'data'       => ['title' => 'Hacked'],
                    ],
                    $context,
                    $resolveInfo
                );
            } catch (GraphQLException $e) {
                $exception = $e;
            }

            $this->assertInstanceOf(
                GraphQLException::class,
                $exception,
                'Expected a GraphQLException to be thrown for non-owner calling upsertPageComponent.'
            );
            $this->assertSame(
                'AUTHORIZATION',
                $exception->getErrorCode(),
                "Expected error code AUTHORIZATION but got {$exception->getErrorCode()}"
            );
        });
    }

    /**
     * Property 5: For any attacker user (not the license owner), calling
     * togglePageComponent must throw a GraphQLException with code AUTHORIZATION.
     */
    public function test_toggle_page_component_throws_authorization_for_non_owner(): void
    {
        $this->forAll(
            Generators::elements(true, false)
        )->then(function (bool $isActive): void {
            $owner     = $this->createUser();
            $attacker  = $this->createUser();
            $license   = $this->createLicense($owner);
            $page      = $this->createPage($license);
            $component = $this->createComponent($page, $license);

            $context     = $this->makeContext($attacker);
            $resolveInfo = $this->createMock(ResolveInfo::class);
            $mutation    = new TogglePageComponent();

            $exception = null;

            try {
                $mutation(
                    null,
                    [
                        'id'        => $component->id,
                        'is_active' => $isActive,
                    ],
                    $context,
                    $resolveInfo
                );
            } catch (GraphQLException $e) {
                $exception = $e;
            }

            $this->assertInstanceOf(
                GraphQLException::class,
                $exception,
                'Expected a GraphQLException to be thrown for non-owner calling togglePageComponent.'
            );
            $this->assertSame(
                'AUTHORIZATION',
                $exception->getErrorCode(),
                "Expected error code AUTHORIZATION but got {$exception->getErrorCode()}"
            );
        });
    }

    /**
     * Property 5: For any attacker user (not the license owner), calling
     * deletePageComponent must throw a GraphQLException with code AUTHORIZATION.
     */
    public function test_delete_page_component_throws_authorization_for_non_owner(): void
    {
        $this->forAll(
            Generators::elements('Hero', 'Text', 'Banner', 'Footer', 'Header')
        )->then(function (string $type): void {
            $owner    = $this->createUser();
            $attacker = $this->createUser();
            $license  = $this->createLicense($owner);
            $page     = $this->createPage($license);

            $component = PageComponent::create([
                'page_id'    => $page->id,
                'license_id' => $license->id,
                'type'       => $type,
                'data'       => ['title' => 'Test'],
                'is_active'  => true,
            ]);

            $context     = $this->makeContext($attacker);
            $resolveInfo = $this->createMock(ResolveInfo::class);
            $mutation    = new DeletePageComponent();

            $exception = null;

            try {
                $mutation(
                    null,
                    [
                        'id'         => $component->id,
                        'license_id' => $license->id,
                    ],
                    $context,
                    $resolveInfo
                );
            } catch (GraphQLException $e) {
                $exception = $e;
            }

            $this->assertInstanceOf(
                GraphQLException::class,
                $exception,
                'Expected a GraphQLException to be thrown for non-owner calling deletePageComponent.'
            );
            $this->assertSame(
                'AUTHORIZATION',
                $exception->getErrorCode(),
                "Expected error code AUTHORIZATION but got {$exception->getErrorCode()}"
            );
        });
    }
}
