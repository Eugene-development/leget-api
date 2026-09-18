<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\GraphQL\Mutations\ToggleCategory;
use App\Models\Category;
use App\Models\License;
use App\Models\Rubric;
use App\Models\User;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

final class ToggleCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('licenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('domain');
            $table->json('catalog_settings')->nullable();
            $table->timestamps();
        });
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->ulid('license_id');
            $table->string('slug');
            $table->timestamps();
        });
        Schema::create('rubrics', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('key');
            $table->string('value');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('key');
            $table->ulid('rubric_id');
            $table->string('value');
            $table->string('slug')->unique();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        (require base_path('../leget-db/database/migrations/2026_08_30_000001_create_catalog_brands_table.php'))->up();
        (require base_path('../leget-db/database/migrations/2026_09_14_120000_create_project_tags_tables.php'))->up();
        (require base_path('../leget-db/database/migrations/2026_09_17_180000_create_appliance_brands_table.php'))->up();
        (require base_path('../leget-db/database/migrations/2026_09_17_190000_add_rubric_to_site_brands.php'))->up();
        (require base_path('../leget-db/database/migrations/2026_09_18_120000_add_tag_destinations.php'))->up();

    }

    private function owner(): User
    {
        return User::create([
            'name' => 'Owner', 'email' => Str::ulid().'@example.com', 'password' => bcrypt('test'),
        ]);
    }

    private function entry(array $attributes = []): Category
    {
        $rubric = Rubric::firstOrCreate(['slug' => 'bytovaya-tehnika'], [
            'key' => (string) Str::ulid(), 'value' => 'Техника',
        ]);

        return Category::create(array_merge([
            'key' => (string) Str::ulid(), 'rubric_id' => $rubric->id,
            'value' => 'Brand', 'slug' => 'brand-'.Str::ulid(),
        ], $attributes));
    }

    private function toggle(?User $user, string $licenseId, string $id, bool $enabled): Category
    {
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn($user);

        return app(ToggleCategory::class)(null, [
            'license_id' => $licenseId, 'id' => $id, 'is_enabled' => $enabled,
        ], $context, $this->createMock(ResolveInfo::class));
    }

    public function test_toggles_preserve_other_entries_and_never_modify_directory_or_other_site_cache(): void
    {
        $owner = $this->owner();
        $license = License::create(['user_id' => $owner->id, 'domain' => 'mine.example.com']);
        $other = License::create(['user_id' => $owner->id, 'domain' => 'also-mine.example.com']);
        $first = $this->entry();
        $second = $this->entry();
        Cache::put('other-site-render', 'untouched', 3600);

        $this->assertFalse($this->toggle($owner, $license->id, $first->id, false)->is_enabled);
        $this->toggle($owner, $license->id, $second->id, false);
        $this->toggle($owner, $license->id, $first->id, true);

        $this->assertSame([$first->id => true, $second->id => false], $license->fresh()->catalog_settings['categories']);
        $this->assertNull($other->fresh()->catalog_settings);
        $this->assertTrue($first->fresh()->is_enabled);
        $this->assertTrue($second->fresh()->is_enabled);
        $this->assertSame('untouched', Cache::get('other-site-render'));
    }

    public function test_foreign_owner_cannot_toggle_a_site(): void
    {
        $license = License::create(['user_id' => $this->owner()->id, 'domain' => 'foreign.example.com']);
        $entry = $this->entry();
        try {
            $this->toggle($this->owner(), $license->id, $entry->id, false);
            $this->fail('Foreign ownership must be rejected');
        } catch (GraphQLException $e) {
            $this->assertSame('This action is unauthorized.', $e->getMessage());
        }
        $this->assertNull($license->fresh()->catalog_settings);
        $this->assertTrue($entry->fresh()->is_enabled);
    }

    public function test_guest_cannot_toggle_a_site(): void
    {
        $license = License::create(['user_id' => $this->owner()->id, 'domain' => 'guest.example.com']);
        $this->expectException(GraphQLException::class);
        $this->toggle(null, $license->id, $this->entry()->id, false);
    }

    public function test_unknown_license_is_rejected(): void
    {
        $this->expectException(GraphQLException::class);
        $this->toggle($this->owner(), (string) Str::ulid(), $this->entry()->id, false);
    }

    public function test_unknown_category_is_rejected_without_creating_settings(): void
    {
        $owner = $this->owner();
        $license = License::create(['user_id' => $owner->id, 'domain' => 'unknown.example.com']);
        $this->expectException(GraphQLException::class);
        $this->toggle($owner, $license->id, (string) Str::ulid(), false);
    }

    public function test_inactive_category_is_rejected(): void
    {
        $owner = $this->owner();
        $license = License::create(['user_id' => $owner->id, 'domain' => 'inactive.example.com']);
        $this->expectException(GraphQLException::class);
        $this->toggle($owner, $license->id, $this->entry(['is_active' => false])->id, true);
    }

    public function test_category_outside_catalog_rubrics_is_rejected(): void
    {
        $owner = $this->owner();
        $license = License::create(['user_id' => $owner->id, 'domain' => 'outside.example.com']);
        $entry = $this->entry();
        $entry->rubric->update(['slug' => 'not-catalog']);
        $this->expectException(GraphQLException::class);
        $this->toggle($owner, $license->id, $entry->id, false);
    }
}
