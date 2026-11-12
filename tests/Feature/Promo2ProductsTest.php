<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\GraphQLException;
use App\GraphQL\Promo2Products as Resolver;
use App\Models\Category;
use App\Models\License;
use App\Models\MebelProject;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\Rubric;
use App\Models\User;
use App\Services\Promo2Products;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

class Promo2ProductsTest extends TestCase
{
    use RefreshDatabase;

    private License $license;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lighthouse.schema_cache.enable' => false]);
        Schema::create('licenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('domain');
            $table->unsignedInteger('template_id');
            $table->json('catalog_settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->ulid('license_id');
            $table->string('slug');
            $table->timestamps();
        });
        foreach ([
            '2026_05_09_000001_create_rubrics_table',
            '2026_05_09_000002_create_categories_table',
            '2026_05_09_000003_create_mebel_projects_table',
            '2026_05_09_160736_add_license_id_to_mebel_projects_table',
            '2026_05_09_000004_create_images_table',
            '2026_09_24_000001_unique_mebel_project_number_per_license',
        ] as $migration) {
            (require base_path('../leget-db/database/migrations/'.$migration.'.php'))->up();
        }
        $rubric = Rubric::create(['key' => (string) Str::ulid(), 'value' => 'Мебель', 'slug' => 'mebel']);
        $this->category = Category::create(['key' => (string) Str::ulid(), 'rubric_id' => $rubric->id, 'value' => 'Кухни', 'slug' => 'kuhni']);
        Category::create(['key' => (string) Str::ulid(), 'rubric_id' => $rubric->id, 'value' => 'Шкафы', 'slug' => 'shkafy']);
        $this->license = $this->site();
    }

    private function site(): License
    {
        $user = User::create(['name' => 'Owner', 'email' => Str::random(16).'@example.com', 'password' => bcrypt('test')]);

        return License::create(['user_id' => $user->id, 'template_id' => 2, 'domain' => Str::random(16).'.example.com']);
    }

    private function context(?User $user = null): GraphQLContext
    {
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn($user ?? $this->license->user);

        return $context;
    }

    private function import(): void
    {
        (require base_path('../leget-db/database/migrations/2026_11_12_120000_persist_promo2_kitchens.php'))->up();
    }

    private function importWardrobes(): void
    {
        (require base_path('../leget-db/database/migrations/2026_11_12_130000_persist_promo2_wardrobes.php'))->up();
    }

    public function test_wardrobes_import_preserves_four_identical_titles_as_separate_products_for_each_site(): void
    {
        $other = $this->site();
        $this->import();
        $this->importWardrobes();
        $this->importWardrobes();
        $service = app(Promo2Products::class);
        $items = $service->items($this->license, 'wardrobe');
        $this->assertCount(4, $items);
        $this->assertCount(4, array_unique(array_column($items, 'id')));
        $this->assertCount(4, array_unique(array_column($items, 'image')));
        $this->assertSame(array_fill(0, 4, 'Шкаф/гардеробная'), array_column($items, 'title'));
        $this->assertNotSame($items[0]['id'], $service->items($other, 'wardrobe')[0]['id']);
        $this->assertCount(15, $service->items($this->license, 'kitchen'));
        $this->assertDatabaseCount('mebel_projects', 38);
        $this->assertDatabaseCount('images', 38);
    }

    public function test_wardrobe_crud_is_isolated_from_kitchens_and_allows_duplicate_display_titles(): void
    {
        $this->import();
        $this->importWardrobes();
        $service = app(Promo2Products::class);
        $items = $service->items($this->license, 'wardrobe');
        $saved = $service->save($this->license, [...$items[0], 'sortOrder' => 90, 'isActive' => false], 'wardrobe');
        $this->assertSame('Шкаф/гардеробная', $saved['title']);
        $this->assertCount(3, $service->items($this->license, 'wardrobe'));
        foreach (['save', 'delete'] as $operation) {
            try {
                $operation === 'save'
                    ? $service->save($this->license, $items[0], 'kitchen')
                    : $service->delete($this->license, $items[0]['id'], 'kitchen');
                $this->fail('Product IDs cannot cross category boundaries');
            } catch (GraphQLException) {
            }
        }
        $this->assertNotNull(MebelProject::find($items[0]['id']));
        $service->delete($this->license, $items[0]['id'], 'wardrobe');
        $service->delete($this->license, $items[0]['id'], 'wardrobe');
        $this->importWardrobes();
        $this->assertCount(3, $service->items($this->license, 'wardrobe', true));
        $this->assertCount(15, $service->items($this->license, 'kitchen'));
    }

    public function test_wardrobe_graphql_uses_the_shared_contract_and_rejects_kitchen_id(): void
    {
        $this->import();
        $this->importWardrobes();
        $token = auth('api')->login($this->license->user);
        $variables = ['licenseId' => $this->license->id, 'kind' => 'WARDROBE'];
        $response = $this->postJson('/graphql', [
            'query' => 'query($licenseId: ID!, $kind: Promo2ProductKind!) { promo2Products(licenseId: $licenseId, kind: $kind) { id title description image isActive sortOrder } kitchenProducts(licenseId: $licenseId) { id } }',
            'variables' => $variables,
        ], ['Authorization' => 'Bearer '.$token]);
        $response->assertOk()->assertJsonMissingPath('errors')->assertJsonCount(4, 'data.promo2Products');
        $response->assertJsonCount(15, 'data.kitchenProducts');
        $item = $response->json('data.promo2Products.0');
        $query = 'mutation($licenseId: ID!, $kind: Promo2ProductKind!, $input: Promo2ProductInput!) { savePromo2Product(licenseId: $licenseId, kind: $kind, input: $input) { id title } }';
        $this->postJson('/graphql', ['query' => $query, 'variables' => [...$variables, 'input' => [...$item, 'title' => 'Обновлённый шкаф']]],
            ['Authorization' => 'Bearer '.$token])->assertJsonMissingPath('errors')->assertJsonPath('data.savePromo2Product.title', 'Обновлённый шкаф');
        $kitchen = app(Promo2Products::class)->items($this->license)[0];
        $this->postJson('/graphql', ['query' => $query, 'variables' => [...$variables, 'input' => $kitchen]],
            ['Authorization' => 'Bearer '.$token])->assertJsonStructure(['errors']);
    }

    public function test_wardrobe_import_preserves_custom_list_and_explicit_empty_list(): void
    {
        $page = Page::create(['license_id' => $this->license->id, 'slug' => '/wardrobes']);
        $component = PageComponent::create(['page_id' => $page->id, 'license_id' => $this->license->id,
            'type' => 'WardrobesGallery', 'data' => ['heading' => 'Свои шкафы', 'items' => [
                ['title' => 'Свой шкаф', 'description' => 'Описание', 'image' => 'https://example.com/custom.webp'],
            ]]]);
        $empty = $this->site();
        $emptyPage = Page::create(['license_id' => $empty->id, 'slug' => '/wardrobes']);
        PageComponent::create(['page_id' => $emptyPage->id, 'license_id' => $empty->id,
            'type' => 'WardrobesGallery', 'data' => ['items' => []]]);
        $this->importWardrobes();
        $this->importWardrobes();
        $this->assertDatabaseCount('mebel_projects', 1);
        $this->assertSame('Свой шкаф', app(Promo2Products::class)->items($this->license, 'wardrobe')[0]['title']);
        $this->assertSame(['heading' => 'Свои шкафы'], $component->fresh()->data);
        $this->assertSame([], app(Promo2Products::class)->items($empty, 'wardrobe'));
    }

    public function test_graphql_owner_can_load_and_save_products_using_the_public_schema(): void
    {
        $this->import();
        $token = auth('api')->login($this->license->user);
        $variables = ['licenseId' => $this->license->id, 'kind' => 'KITCHEN'];
        $response = $this->postJson('/graphql', [
            'query' => 'query($licenseId: ID!, $kind: Promo2ProductKind!) { promo2Products(licenseId: $licenseId, kind: $kind) { id title description image isActive sortOrder } }',
            'variables' => $variables,
        ], ['Authorization' => 'Bearer '.$token]);
        $response->assertOk()->assertJsonMissingPath('errors')->assertJsonCount(15, 'data.promo2Products');
        $item = $response->json('data.promo2Products.0');
        $this->postJson('/graphql', [
            'query' => 'mutation($licenseId: ID!, $kind: Promo2ProductKind!, $input: Promo2ProductInput!) { savePromo2Product(licenseId: $licenseId, kind: $kind, input: $input) { id title } }',
            'variables' => [...$variables, 'input' => [...$item, 'title' => 'Изменено через GraphQL']],
        ], ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonMissingPath('errors')
            ->assertJsonPath('data.savePromo2Product.id', $item['id'])
            ->assertJsonPath('data.savePromo2Product.title', 'Изменено через GraphQL');
    }

    public function test_import_moves_fifteen_products_and_images_to_each_site_and_is_idempotent(): void
    {
        $other = $this->site();
        $this->import();
        $this->import();
        $this->assertDatabaseCount('mebel_projects', 30);
        $this->assertDatabaseCount('images', 30);
        $service = app(Promo2Products::class);
        $items = $service->items($this->license);
        $this->assertCount(15, $items);
        $this->assertSame('Бостон', $items[0]['title']);
        $this->assertSame('Флитвуд', $items[14]['title']);
        $this->assertStringEndsWith('/cam-1-1600x0.webp', $items[0]['image']);
        $this->assertNotSame($items[0]['id'], $service->items($other)[0]['id']);
    }

    public function test_import_preserves_custom_collection_other_settings_and_empty_list(): void
    {
        $page = Page::create(['license_id' => $this->license->id, 'slug' => '/kitchens']);
        $component = PageComponent::create(['page_id' => $page->id, 'license_id' => $this->license->id,
            'type' => 'KitchensGallery', 'data' => ['heading' => 'Свой заголовок', 'items' => [
                ['title' => 'Своя кухня', 'description' => 'Свой текст', 'image' => 'https://example.com/photo.webp'],
            ]]]);
        $empty = $this->site();
        $emptyPage = Page::create(['license_id' => $empty->id, 'slug' => '/kitchens']);
        PageComponent::create(['page_id' => $emptyPage->id, 'license_id' => $empty->id,
            'type' => 'KitchensGallery', 'data' => ['items' => []]]);
        $this->import();
        $this->import();
        $this->assertDatabaseCount('mebel_projects', 1);
        $this->assertSame('Своя кухня', app(Promo2Products::class)->items($this->license)[0]['title']);
        $this->assertSame(['heading' => 'Свой заголовок'], $component->fresh()->data);
        $this->assertSame([], app(Promo2Products::class)->items($empty));
    }

    public function test_save_updates_catalog_and_cache_hides_without_fallback_and_delete_can_be_retried(): void
    {
        $this->import();
        $service = app(Promo2Products::class);
        $items = $service->items($this->license);
        Cache::tags(["license:{$this->license->id}"])->put('old-render', 'stale');
        $saved = app(Resolver::class)->save(null, ['licenseId' => $this->license->id, 'input' => [
            ...$items[0], 'title' => 'Новое название', 'description' => 'Новый текст', 'sortOrder' => 99, 'isActive' => false,
        ]], $this->context());
        $this->assertFalse($saved['isActive']);
        $this->assertSame('Новое название', MebelProject::findOrFail($saved['id'])->meta['promo2_title']);
        $this->assertNull(Cache::tags(["license:{$this->license->id}"])->get('old-render'));
        $this->assertCount(14, $service->items($this->license));
        $this->assertCount(15, app(Resolver::class)->listing(null, ['licenseId' => $this->license->id], $this->context()));
        foreach ($items as $item) {
            $service->delete($this->license, $item['id']);
            $service->delete($this->license, $item['id']);
        }
        $this->assertSame([], $service->items($this->license));
        // Re-running the import must not bring removed kitchens back.
        $this->import();
        $this->assertSame([], $service->items($this->license));
    }

    public function test_owner_cannot_read_or_change_another_sites_products_or_shared_rows(): void
    {
        $other = $this->site();
        $this->import();
        $resolver = app(Resolver::class);
        try {
            $resolver->listing(null, ['licenseId' => $other->id], $this->context());
            $this->fail('Another owner must not read the manager list');
        } catch (GraphQLException) {
        }
        // Even another site owned by this same user cannot be edited under the wrong license.
        $other->update(['user_id' => $this->license->user_id]);
        $foreign = app(Promo2Products::class)->items($other)[0];
        try {
            $resolver->save(null, ['licenseId' => $this->license->id, 'input' => $foreign], $this->context());
            $this->fail('Cross-license update must be rejected');
        } catch (GraphQLException) {
        }
        try {
            $resolver->delete(null, ['licenseId' => $this->license->id, 'id' => $foreign['id']], $this->context());
            $this->fail('Cross-license deletion must be rejected');
        } catch (GraphQLException) {
        }
        $this->assertNotNull(MebelProject::find($foreign['id']));
        MebelProject::whereKey($foreign['id'])->update(['license_id' => null]);
        $this->assertCount(15, app(Promo2Products::class)->items($this->license));
    }

    public function test_foreign_photo_and_invalid_input_fail_without_changing_existing_record(): void
    {
        $this->import();
        $service = app(Promo2Products::class);
        $item = $service->items($this->license)[0];
        foreach ([['image' => 'https://example.com/foreign.webp'], ['title' => '  '], ['sortOrder' => -1]] as $invalid) {
            try {
                $service->save($this->license, [...$item, ...$invalid]);
                $this->fail('Invalid data must be rejected');
            } catch (ValidationException) {
            }
        }
        $this->assertSame($item, $service->items($this->license)[0]);
        $this->assertDatabaseCount('images', 15);
    }

    public function test_create_with_verified_upload_is_site_owned_even_in_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['filesystems.disks.yandex.endpoint' => 'https://storage.yandexcloud.net', 'filesystems.disks.yandex.bucket' => 'test']);
        Storage::fake('yandex');
        $path = 'mebel/'.md5($this->license->id).'/'.str_repeat('a', 40).'.png';
        Storage::disk('yandex')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII='));
        $saved = app(Resolver::class)->save(null, ['licenseId' => $this->license->id, 'input' => [
            'title' => 'Новая кухня', 'description' => 'Описание', 'sortOrder' => 1, 'isActive' => true,
            'image' => 'https://storage.yandexcloud.net/test/'.$path,
        ]], $this->context());
        $this->assertSame($this->license->id, MebelProject::findOrFail($saved['id'])->license_id);
        $this->assertSame('Новая кухня', $saved['title']);
        $this->assertDatabaseCount('images', 1);
    }
}
