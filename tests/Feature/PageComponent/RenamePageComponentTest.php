<?php

declare(strict_types=1);

namespace Tests\Feature\PageComponent;

use App\Exceptions\GraphQLException;
use App\GraphQL\Mutations\DeletePageComponent;
use App\GraphQL\Mutations\RenamePageComponent;
use App\GraphQL\Mutations\UpsertPageComponent;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\User;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

/**
 * Имя и назначение блока на конкретном сайте.
 *
 * Главное, что здесь проверяется:
 *   • переименовать можно блок, у которого ещё нет строки page_components;
 *   • пустая строка — это «убрать имя», а не «оставить как было»;
 *   • роль сверяется со справочником, потому что внешнего ключа на него нет;
 *   • сброс контента НЕ стирает имя.
 *
 * Последнее — не мелочь: `deletePageComponent` удаляет строку, и без оговорки
 * сброс текстов уносил бы вместе с ними подпись, которую тенант задавал отдельно
 * и сбрасывать не просил.
 */
final class RenamePageComponentTest extends TestCase
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
                $table->foreign('license_id')->references('id')->on('licenses')->onDelete('cascade');
                $table->unique(['license_id', 'slug']);
            });
        }
    }

    public function test_names_a_block_that_has_no_row_yet(): void
    {
        [$user, $license, $page] = $this->scene();

        $this->assertDatabaseCount('page_components', 0);

        $result = $this->rename($user, [
            'page_id'    => $page->id,
            'license_id' => $license->id,
            'type'       => 'Incentives',
            'label'      => 'Почему нас выбирают',
            'role_slug'  => 'benefits',
        ]);

        $this->assertSame('Почему нас выбирают', $result->label);
        $this->assertSame('benefits', $result->role_slug);
        // Данные не выдумываются: тенант дал блоку имя, а не контент.
        $this->assertSame([], $result->data);
    }

    public function test_empty_label_removes_the_name(): void
    {
        [$user, $license, $page] = $this->scene();

        $this->rename($user, [
            'page_id' => $page->id, 'license_id' => $license->id, 'type' => 'Incentives',
            'label' => 'Временное имя', 'role_slug' => 'benefits',
        ]);

        // Пустая строка от клиента — осмысленная операция «убрать имя», после неё
        // подпись обязана вернуться к каскаду, а не остаться пустой строкой.
        $result = $this->rename($user, [
            'page_id' => $page->id, 'license_id' => $license->id, 'type' => 'Incentives',
            'label' => '   ', 'role_slug' => '',
        ]);

        $this->assertNull($result->label);
        $this->assertNull($result->role_slug);
    }

    public function test_unknown_role_is_rejected(): void
    {
        [$user, $license, $page] = $this->scene();

        $this->expectException(GraphQLException::class);

        $this->rename($user, [
            'page_id' => $page->id, 'license_id' => $license->id, 'type' => 'Incentives',
            'label' => null, 'role_slug' => 'нет-такой-роли',
        ]);
    }

    public function test_retired_role_cannot_be_chosen(): void
    {
        [$user, $license, $page] = $this->scene();

        config(['component_roles.retired' => ['benefits']]);

        $this->expectException(GraphQLException::class);

        $this->rename($user, [
            'page_id' => $page->id, 'license_id' => $license->id, 'type' => 'Incentives',
            'label' => null, 'role_slug' => 'benefits',
        ]);
    }

    public function test_foreign_license_is_rejected(): void
    {
        [, $license, $page] = $this->scene();
        $stranger = $this->createUser();

        $this->expectException(GraphQLException::class);

        $this->rename($stranger, [
            'page_id' => $page->id, 'license_id' => $license->id, 'type' => 'Incentives',
            'label' => 'Чужое', 'role_slug' => null,
        ]);
    }

    public function test_content_reset_keeps_the_name(): void
    {
        [$user, $license, $page] = $this->scene();
        $context = $this->contextFor($user);

        app(UpsertPageComponent::class)(null, [
            'page_id' => $page->id, 'license_id' => $license->id,
            'type' => 'Incentives', 'data' => ['title' => 'Правленый заголовок'],
        ], $context, $this->createMock(ResolveInfo::class));

        $this->rename($user, [
            'page_id' => $page->id, 'license_id' => $license->id, 'type' => 'Incentives',
            'label' => 'Почему нас выбирают', 'role_slug' => 'benefits',
        ]);

        $row = PageComponent::where('page_id', $page->id)->where('type', 'Incentives')->firstOrFail();

        app(DeletePageComponent::class)(null, [
            'id' => $row->id, 'license_id' => $license->id,
        ], $context, $this->createMock(ResolveInfo::class));

        $after = PageComponent::find($row->id);

        $this->assertNotNull($after, 'Сброс контента удалил строку вместе с именем блока');
        $this->assertSame('Почему нас выбирают', $after->label);
        $this->assertSame([], $after->data, 'Контент должен вернуться к дефолтам шаблона');
    }

    public function test_unnamed_block_is_still_deleted_on_reset(): void
    {
        [$user, $license, $page] = $this->scene();
        $context = $this->contextFor($user);

        app(UpsertPageComponent::class)(null, [
            'page_id' => $page->id, 'license_id' => $license->id,
            'type' => 'Incentives', 'data' => ['title' => 'Правленый заголовок'],
        ], $context, $this->createMock(ResolveInfo::class));

        $row = PageComponent::where('page_id', $page->id)->firstOrFail();

        app(DeletePageComponent::class)(null, [
            'id' => $row->id, 'license_id' => $license->id,
        ], $context, $this->createMock(ResolveInfo::class));

        // Без имени поведение прежнее — строка уходит целиком.
        $this->assertNull(PageComponent::find($row->id));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function rename(User $user, array $args): PageComponent
    {
        return app(RenamePageComponent::class)(
            null,
            $args,
            $this->contextFor($user),
            $this->createMock(ResolveInfo::class),
        );
    }

    private function contextFor(User $user): GraphQLContext
    {
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn($user);

        return $context;
    }

    /**
     * @return array{0: User, 1: License, 2: Page}
     */
    private function scene(): array
    {
        $user    = $this->createUser();
        $license = License::create([
            'user_id'     => $user->id,
            'domain'      => 'test-' . uniqid() . '.example.com',
            'name'        => 'Test Site',
            'template_id' => 1,
            'is_active'   => true,
            'status'      => 'active',
        ]);
        $page = Page::create(['license_id' => $license->id, 'slug' => '/']);

        return [$user, $license, $page];
    }

    private function createUser(): User
    {
        return User::create([
            'name'     => 'Test User',
            'email'    => 'test-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);
    }
}
