<?php

namespace Tests\Feature\PageComponent;

use App\Exceptions\GraphQLException;
use App\GraphQL\Mutations\UpsertPageComponent;
use App\Models\License;
use App\Models\Page;
use App\Models\User;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

/**
 * Verifies that the upsertPageComponent mutation returns a VALIDATION error
 * when called with a non-existent licenseId or pageId.
 *
 * Validates: Requirements 4.6
 */
class ValidationErrorTest extends TestCase
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

    private function makeMutation(User $user): UpsertPageComponent
    {
        return new UpsertPageComponent();
    }

    private function makeContext(User $user): GraphQLContext
    {
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn($user);

        return $context;
    }

    /**
     * Calling upsertPageComponent with a non-existent license_id must throw
     * a GraphQLException with code VALIDATION.
     */
    public function test_non_existent_license_id_throws_validation_error(): void
    {
        $user    = $this->createUser();
        $context = $this->makeContext($user);
        $resolveInfo = $this->createMock(ResolveInfo::class);
        $mutation    = $this->makeMutation($user);

        $exception = null;

        try {
            $mutation(
                null,
                [
                    'license_id' => 'non-existent-license-id-00000',
                    'page_id'    => 999,
                    'type'       => 'Hero',
                    'data'       => ['title' => 'Hello'],
                ],
                $context,
                $resolveInfo
            );
        } catch (GraphQLException $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(GraphQLException::class, $exception, 'Expected a GraphQLException to be thrown.');
        $this->assertSame('VALIDATION', $exception->getErrorCode());
    }

    /**
     * Calling upsertPageComponent with a valid license_id but a non-existent
     * page_id must throw a GraphQLException with code VALIDATION.
     */
    public function test_non_existent_page_id_throws_validation_error(): void
    {
        $user    = $this->createUser();
        $license = $this->createLicense($user);
        $context = $this->makeContext($user);
        $resolveInfo = $this->createMock(ResolveInfo::class);
        $mutation    = $this->makeMutation($user);

        $exception = null;

        try {
            $mutation(
                null,
                [
                    'license_id' => $license->id,
                    'page_id'    => 999999,
                    'type'       => 'Hero',
                    'data'       => ['title' => 'Hello'],
                ],
                $context,
                $resolveInfo
            );
        } catch (GraphQLException $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(GraphQLException::class, $exception, 'Expected a GraphQLException to be thrown.');
        $this->assertSame('VALIDATION', $exception->getErrorCode());
    }
}
