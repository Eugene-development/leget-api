<?php

namespace Tests\Feature\PageComponent\Property;

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
 * Feature: page-components-refactor, Property 4: Upsert Semantics
 *
 * For any valid pair (pageId, type) and arbitrary data: the first call to
 * upsertPageComponent creates exactly one record; subsequent calls with the
 * same (pageId, type) but different data update the existing record without
 * creating a duplicate. After any number of calls, exactly one record exists
 * with that (page_id, type) pair.
 *
 * Validates: Requirements 4.2
 */
class UpsertSemanticsTest extends TestCase
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

    private function makeContext(User $user): GraphQLContext
    {
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn($user);

        return $context;
    }

    /**
     * Property 4: For any valid (pageId, type) pair, calling upsertPageComponent
     * N times (2–10) with the same (pageId, type) but different data each time
     * results in exactly one record in the database, and its data matches the
     * last call's data.
     */
    public function test_upsert_semantics_exactly_one_record_after_n_calls(): void
    {
        $this->forAll(
            Generators::choose(2, 10),
            Generators::elements('Hero', 'Text', 'Banner', 'Footer', 'Header', 'Gallery', 'CTA')
        )->then(function (int $n, string $type): void {
            $user    = $this->createUser();
            $license = $this->createLicense($user);
            $page    = $this->createPage($license);

            $context     = $this->makeContext($user);
            $resolveInfo = $this->createMock(ResolveInfo::class);
            $mutation    = new UpsertPageComponent();

            $lastData = null;

            for ($i = 0; $i < $n; $i++) {
                $lastData = ['iteration' => $i, 'value' => 'data-' . $i, 'extra' => uniqid()];

                $mutation(
                    null,
                    [
                        'page_id'    => $page->id,
                        'license_id' => $license->id,
                        'type'       => $type,
                        'data'       => $lastData,
                    ],
                    $context,
                    $resolveInfo
                );
            }

            // Assert exactly one record exists for this (page_id, type) pair
            $count = PageComponent::where('page_id', $page->id)
                ->where('type', $type)
                ->count();

            $this->assertSame(
                1,
                $count,
                "After {$n} upsert calls with type '{$type}', expected exactly 1 record but found {$count}"
            );

            // Assert the data matches the last call's data
            $component = PageComponent::where('page_id', $page->id)
                ->where('type', $type)
                ->first();

            $this->assertNotNull($component, "Expected a PageComponent record to exist");
            $this->assertSame(
                $lastData,
                $component->data,
                "The stored data does not match the last upsert call's data"
            );
        });
    }
}
