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
 * Feature: page-components-refactor, Property 3: Data Round-Trip Serialization
 *
 * For any PHP array of arbitrary structure (nesting, value types), saved in
 * the `data` field of `PageComponent`, after reading the record from the
 * database, the obtained array must be identical to the original.
 *
 * Validates: Requirements 2.3
 */
class DataRoundTripTest extends TestCase
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
     * Build a generator for a flat array with string keys and scalar values
     * (strings, integers, booleans, nulls).
     *
     * @return \Eris\Generator
     */
    private function scalarValueGenerator(): \Eris\Generator
    {
        return Generators::oneOf(
            Generators::string(),
            Generators::choose(-1000, 1000),
            Generators::elements([true, false]),
            Generators::constant(null)
        );
    }

    /**
     * Build a generator for a flat associative array with string keys.
     * Keys are non-empty strings; values are scalars.
     *
     * @return \Eris\Generator
     */
    private function flatArrayGenerator(): \Eris\Generator
    {
        // Generate a list of [key, value] pairs and fold into an associative array.
        // Note: Eris\Generators::map(callable $fn, Generator $gen) — callable first.
        return Generators::map(
            function (array $pairs): array {
                $result = [];
                foreach ($pairs as [$key, $value]) {
                    $result[$key] = $value;
                }
                return $result;
            },
            Generators::seq(
                Generators::tuple(
                    Generators::suchThat(
                        fn (string $k) => strlen($k) > 0,
                        Generators::string()
                    ),
                    $this->scalarValueGenerator()
                )
            )
        );
    }

    /**
     * Build a generator for a nested associative array (up to 2 levels deep).
     * Top-level keys are non-empty strings; values are either scalars or
     * flat associative arrays.
     *
     * @return \Eris\Generator
     */
    private function nestedArrayGenerator(): \Eris\Generator
    {
        $leafGenerator = Generators::oneOf(
            $this->scalarValueGenerator(),
            $this->flatArrayGenerator()
        );

        return Generators::map(
            function (array $pairs): array {
                $result = [];
                foreach ($pairs as [$key, $value]) {
                    $result[$key] = $value;
                }
                return $result;
            },
            Generators::seq(
                Generators::tuple(
                    Generators::suchThat(
                        fn (string $k) => strlen($k) > 0,
                        Generators::string()
                    ),
                    $leafGenerator
                )
            )
        );
    }

    /**
     * Property 3: For any PHP array saved in the `data` field of PageComponent,
     * after reading the record back from the database, the array must be
     * identical to the original.
     *
     * JSON serialization preserves string keys and scalar types (string, int,
     * bool, null) as well as nested arrays with string keys. The cast
     * `data => 'array'` in PageComponent::casts() must round-trip correctly.
     */
    public function test_data_field_round_trips_through_database(): void
    {
        $this->forAll(
            $this->nestedArrayGenerator()
        )->then(function (array $originalData): void {
            $user      = $this->createUser();
            $license   = $this->createLicense($user);
            $page      = $this->createPage($license, '/test-' . uniqid());

            $component = PageComponent::create([
                'page_id'    => $page->id,
                'license_id' => $license->id,
                'type'       => 'TestComponent',
                'data'       => $originalData,
                'is_active'  => true,
            ]);

            // Read back from the database (fresh query, no model cache)
            $fresh = PageComponent::find($component->id);

            $this->assertNotNull(
                $fresh,
                'PageComponent record should exist in the database after creation'
            );

            $this->assertEquals(
                $originalData,
                $fresh->data,
                'The `data` field read from the database must equal the original array'
            );
        });
    }
}
