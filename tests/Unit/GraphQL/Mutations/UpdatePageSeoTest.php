<?php

namespace Tests\Unit\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\GraphQL\Mutations\UpdatePage;
use App\Models\License;
use App\Models\Page;
use App\Models\User;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

class UpdatePageSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('licenses', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('name')->nullable();
            $table->text('meta_description')->nullable();
            $table->unsignedInteger('template_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('license_id', 26);
            $table->string('slug');
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('seo_keywords')->nullable();
            $table->timestamps();
            $table->unique(['license_id', 'slug']);
        });
    }

    public function test_owner_can_save_and_clear_page_seo_fields(): void
    {
        [$owner, $license, $page] = $this->makePage('/about');
        $mutation = app(UpdatePage::class);

        $updated = $mutation(null, [
            'id' => $page->id,
            'license_id' => $license->id,
            'seo_title' => '  О компании  ',
            'seo_description' => '  История мастерской  ',
            'seo_keywords' => 'мебель, интерьер',
        ], $this->context($owner), $this->resolveInfo());

        $this->assertSame('О компании', $updated->seo_title);
        $this->assertSame('История мастерской', $updated->seo_description);
        $this->assertSame('мебель, интерьер', $updated->seo_keywords);

        $cleared = $mutation(null, [
            'id' => $page->id,
            'license_id' => $license->id,
            'seo_title' => '',
            'seo_description' => null,
            'seo_keywords' => '   ',
        ], $this->context($owner), $this->resolveInfo());

        $this->assertNull($cleared->seo_title);
        $this->assertNull($cleared->seo_description);
        $this->assertNull($cleared->seo_keywords);
    }

    public function test_non_owner_cannot_update_page_seo(): void
    {
        [, $license, $page] = $this->makePage('/about');
        $attacker = $this->makeUser();

        $this->expectException(GraphQLException::class);
        $this->expectExceptionMessage('This action is unauthorized.');

        try {
            app(UpdatePage::class)(null, [
                'id' => $page->id,
                'license_id' => $license->id,
                'seo_title' => 'Чужой title',
            ], $this->context($attacker), $this->resolveInfo());
        } catch (GraphQLException $exception) {
            $this->assertSame('AUTHORIZATION', $exception->getErrorCode());
            throw $exception;
        }
    }

    public function test_dynamic_seo_rejects_unknown_placeholder(): void
    {
        [$owner, $license, $page] = $this->makePage('/mebel/{category}');

        $this->expectException(GraphQLException::class);

        try {
            app(UpdatePage::class)(null, [
                'id' => $page->id,
                'license_id' => $license->id,
                'seo_title' => '{unknown} — {site}',
            ], $this->context($owner), $this->resolveInfo());
        } catch (GraphQLException $exception) {
            $this->assertSame('VALIDATION', $exception->getErrorCode());
            throw $exception;
        }
    }

    /** @return array{User, License, Page} */
    private function makePage(string $slug): array
    {
        $owner = $this->makeUser();
        $license = License::create([
            'user_id' => $owner->id,
            'domain' => 'site-'.uniqid().'.example.com',
            'name' => 'Test Site',
            'is_active' => true,
            'status' => 'active',
        ]);
        $page = Page::create([
            'license_id' => $license->id,
            'slug' => $slug,
        ]);

        return [$owner, $license, $page];
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'User '.uniqid(),
            'email' => 'user-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    private function context(User $user): GraphQLContext
    {
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn($user);

        return $context;
    }

    private function resolveInfo(): ResolveInfo
    {
        return $this->createMock(ResolveInfo::class);
    }
}
