<?php

namespace Tests\Feature\PageComponent;

use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies that the is_active field on PageComponent is correctly cast
 * to a PHP boolean when read back from the database.
 *
 * Validates: Requirements 2.4
 */
class IsActiveCastTest extends TestCase
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
     * A PageComponent created with is_active = true should read back
     * as PHP boolean true (not integer 1 or string "1").
     */
    public function test_is_active_true_is_cast_to_boolean(): void
    {
        $user    = $this->createUser();
        $license = $this->createLicense($user);
        $page    = $this->createPage($license);

        PageComponent::create([
            'page_id'    => $page->id,
            'license_id' => $license->id,
            'type'       => 'Hero',
            'data'       => ['title' => 'Welcome'],
            'is_active'  => true,
        ]);

        $component = PageComponent::where('page_id', $page->id)
            ->where('type', 'Hero')
            ->first();

        $this->assertNotNull($component);
        $this->assertIsBool($component->is_active);
        $this->assertTrue($component->is_active);
    }

    /**
     * A PageComponent created with is_active = false should read back
     * as PHP boolean false (not integer 0 or string "0").
     */
    public function test_is_active_false_is_cast_to_boolean(): void
    {
        $user    = $this->createUser();
        $license = $this->createLicense($user);
        $page    = $this->createPage($license);

        PageComponent::create([
            'page_id'    => $page->id,
            'license_id' => $license->id,
            'type'       => 'Text',
            'data'       => ['content' => 'Hello'],
            'is_active'  => false,
        ]);

        $component = PageComponent::where('page_id', $page->id)
            ->where('type', 'Text')
            ->first();

        $this->assertNotNull($component);
        $this->assertIsBool($component->is_active);
        $this->assertFalse($component->is_active);
    }

    /**
     * A raw integer 1 inserted directly via DB should read back as
     * PHP boolean true through the Eloquent cast.
     */
    public function test_raw_integer_1_is_cast_to_boolean_true(): void
    {
        $user    = $this->createUser();
        $license = $this->createLicense($user);
        $page    = $this->createPage($license);

        $ulid = (new PageComponent)->newUniqueId();

        DB::table('page_components')->insert([
            'id'         => $ulid,
            'page_id'    => $page->id,
            'license_id' => $license->id,
            'type'       => 'Hero',
            'data'       => json_encode(['title' => 'Raw']),
            'is_active'  => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = PageComponent::find($ulid);

        $this->assertNotNull($component);
        $this->assertIsBool($component->is_active);
        $this->assertTrue($component->is_active);
    }

    /**
     * A raw integer 0 inserted directly via DB should read back as
     * PHP boolean false through the Eloquent cast.
     */
    public function test_raw_integer_0_is_cast_to_boolean_false(): void
    {
        $user    = $this->createUser();
        $license = $this->createLicense($user);
        $page    = $this->createPage($license);

        $ulid = (new PageComponent)->newUniqueId();

        DB::table('page_components')->insert([
            'id'         => $ulid,
            'page_id'    => $page->id,
            'license_id' => $license->id,
            'type'       => 'Text',
            'data'       => json_encode(['content' => 'Raw']),
            'is_active'  => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = PageComponent::find($ulid);

        $this->assertNotNull($component);
        $this->assertIsBool($component->is_active);
        $this->assertFalse($component->is_active);
    }
}
