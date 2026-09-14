<?php

declare(strict_types=1);

namespace Tests\Feature\MebelProject;

use App\Exceptions\GraphQLException;
use App\GraphQL\Mutations\CreateTag;
use App\GraphQL\Mutations\DeleteMebelProject;
use App\GraphQL\Mutations\UpsertMebelProject;
use App\Models\Category;
use App\Models\License;
use App\Models\MebelProject;
use App\Models\Rubric;
use App\Models\Tag;
use App\Models\TagGroup;
use App\Models\User;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

/**
 * Запись паспорта сданной работы — единственная точка правки этих полей.
 *
 * Страница `/projects` их только показывает, поэтому если здесь что-то
 * молча перестанет сохраняться, узнать об этом будет негде: лента просто
 * не нарисует строку, и это выглядит как «у проекта не заполнено».
 */
class ProjectPassportTest extends TestCase
{
    use RefreshDatabase;

    private UpsertMebelProject $mutation;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mutation = app(UpsertMebelProject::class);
        (require base_path('../leget-db/database/migrations/2026_09_14_120000_create_project_tags_tables.php'))->up();

        if (! Schema::hasTable('licenses')) {
            Schema::create('licenses', function (Blueprint $table) {
                $table->string('id', 26)->primary();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('domain')->unique();
                $table->string('name')->nullable();
                $table->unsignedInteger('template_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rubrics')) {
            Schema::create('rubrics', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('key')->unique();
                $table->boolean('is_active')->default(true);
                $table->string('value');
                $table->string('slug')->unique();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('key')->unique();
                $table->ulid('rubric_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_enabled')->default(true);
                $table->string('value');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('mebel_projects')) {
            Schema::create('mebel_projects', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('key')->nullable();
                $table->ulid('category_id');
                $table->ulid('license_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('value')->nullable();
                $table->string('slug')->nullable();
                $table->text('description')->nullable();
                $table->text('short_description')->nullable();
                $table->date('completed_at')->nullable();
                $table->string('object_address')->nullable();
                $table->decimal('price', 12, 2)->nullable();
                $table->decimal('old_price', 12, 2)->nullable();
                $table->json('meta')->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_new')->default(false);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $rubric = Rubric::create([
            'key' => (string) Str::ulid(),
            'value' => 'Мебель',
            'slug' => 'mebel',
        ]);

        $this->category = Category::create([
            'key' => (string) Str::ulid(),
            'rubric_id' => $rubric->id,
            'value' => 'Кухни',
            'slug' => 'kitchens',
        ]);
    }

    public function test_saves_passport_fields_on_create(): void
    {
        $project = $this->upsert([
            'value' => 'Кухня в квартире 96 м²',
            'completed_at' => Carbon::parse('2026-03-14'),
            'object_address' => 'Москва, Хамовники, ул. Примерная, 1',
            'maker' => 'Собственное производство',
            'hardware_brands' => ['Blum', 'Hettich'],
            'appliance_brands' => ['Bosch'],
        ]);

        $this->assertSame('2026-03-14', $project->completed_at->format('Y-m-d'));
        $this->assertSame('Москва, Хамовники, ул. Примерная, 1', $project->object_address);
        $this->assertSame('Собственное производство', $project->meta['maker']);
        $this->assertSame(['Blum', 'Hettich'], $project->meta['hardware_brands']);
        $this->assertSame(['Bosch'], $project->meta['appliance_brands']);
    }

    /** Явный null стирает дату — работа уходит из ленты, оставаясь в каталоге. */
    public function test_explicit_null_clears_completed_at(): void
    {
        $project = $this->upsert([
            'value' => 'Кухня',
            'completed_at' => Carbon::parse('2026-03-14'),
        ]);

        $cleared = $this->upsert([
            'id' => $project->id,
            'value' => 'Кухня',
            'completed_at' => null,
        ]);

        $this->assertNull($cleared->completed_at);
        $this->assertTrue($cleared->is_active, 'Очистка даты не должна снимать проект с публикации');
    }

    /** Не присланное поле не трогается — панель может слать частичный ввод. */
    public function test_absent_field_is_left_untouched(): void
    {
        $project = $this->upsert([
            'value' => 'Кухня',
            'completed_at' => Carbon::parse('2026-03-14'),
            'maker' => 'Своё производство',
        ]);

        $updated = $this->upsert([
            'id' => $project->id,
            'value' => 'Кухня переименованная',
        ]);

        $this->assertSame('2026-03-14', $updated->completed_at->format('Y-m-d'));
        $this->assertSame('Своё производство', $updated->meta['maker']);
    }

    /**
     * Бренды переиндексируются после отсева.
     *
     * Без `array_values` дырявый список сериализуется в JSON-ОБЪЕКТ
     * (`{"1":"Hettich"}`), а лента ждёт массив и молча покажет пустую строку.
     */
    public function test_brands_are_trimmed_deduplicated_and_reindexed(): void
    {
        $project = $this->upsert([
            'value' => 'Кухня',
            'hardware_brands' => ['', '  Blum ', 'Blum', 'Hettich'],
        ]);

        $this->assertSame(['Blum', 'Hettich'], $project->meta['hardware_brands']);
        $this->assertArrayHasKey(0, $project->meta['hardware_brands']);

        $raw = json_decode((string) $project->getRawOriginal('meta'), true);
        $this->assertSame(['Blum', 'Hettich'], $raw['hardware_brands']);
    }

    /** Чужие ключи `meta` переживают запись паспорта. */
    public function test_foreign_meta_keys_survive(): void
    {
        $project = MebelProject::create([
            'key' => (string) Str::ulid(),
            'category_id' => $this->category->id,
            'value' => 'Кухня',
            'slug' => 'kuhnya-foreign-meta',
            'meta' => ['legacy_attribute' => 'не трогать'],
        ]);

        $updated = $this->upsert([
            'id' => $project->id,
            'value' => 'Кухня',
            'maker' => 'Своё производство',
        ]);

        $this->assertSame('не трогать', $updated->meta['legacy_attribute']);
        $this->assertSame('Своё производство', $updated->meta['maker']);
    }

    /** Пустые значения не оседают в `meta` ключами со значением null. */
    public function test_blank_values_are_dropped_from_meta(): void
    {
        $project = $this->upsert([
            'value' => 'Кухня',
            'maker' => '   ',
            'hardware_brands' => [],
            'appliance_brands' => [],
        ]);

        $this->assertNull($project->meta);
    }

    public function test_tag_dictionary_has_the_six_groups(): void
    {
        $this->assertSame([
            'Бренд техники', 'Бренд сантехники', 'Материал фасадов',
            'Материал столешницы', 'Бренд столешницы', 'Фабрика изготовитель',
        ], TagGroup::orderBy('sort_order')->pluck('name')->all());
        $response = $this->postJson('/graphql', ['query' => '{ tagGroups { id name tags { id name } } }']);
        $response->assertOk()->assertJsonCount(6, 'data.tagGroups')->assertJsonMissingPath('errors');
    }

    public function test_tags_are_shared_by_projects_and_sync_preserves_other_morph_types(): void
    {
        $a = $this->tag('Bosch');
        $b = $this->tag('Miele');
        $first = $this->upsert(['value' => 'Первый', 'tag_ids' => [$a->id, $b->id]]);
        $second = $this->upsert(['value' => 'Второй', 'tag_ids' => [$a->id]]);
        $this->assertCount(2, $first->tags);
        $this->assertCount(2, $a->mebelProjects);
        DB::table('taggables')->insert(['tag_id' => $b->id, 'taggable_id' => $first->id, 'taggable_type' => 'other-model']);
        $updated = $this->upsert(['id' => $first->id, 'value' => 'Первый', 'tag_ids' => [$b->id]]);
        $this->assertSame([$b->id], $updated->tags->modelKeys());
        $this->assertSame([$a->id], $second->fresh()->tags->modelKeys());
        $this->assertDatabaseHas('taggables', ['taggable_type' => 'other-model', 'tag_id' => $b->id]);
    }

    public function test_omitted_tags_survive_and_empty_array_clears_them(): void
    {
        $tag = $this->tag('Bosch');
        $project = $this->upsert(['value' => 'Кухня', 'tag_ids' => [$tag->id]]);
        $updated = $this->upsert(['id' => $project->id, 'value' => 'Новое имя']);
        $this->assertSame([$tag->id], $updated->tags->modelKeys());
        $cleared = $this->upsert(['id' => $project->id, 'value' => 'Новое имя', 'tag_ids' => []]);
        $this->assertCount(0, $cleared->tags);
        $this->assertDatabaseCount('tags', 1);
    }

    public function test_invalid_tag_does_not_partially_save_project(): void
    {
        $tag = $this->tag('Bosch');
        $project = $this->upsert(['value' => 'Кухня', 'tag_ids' => [$tag->id]]);
        foreach ([[Str::ulid()->toString()], [$tag->id, $tag->id], null] as $ids) {
            try {
                $this->upsert(['id' => $project->id, 'value' => 'Испорчено', 'tag_ids' => $ids]);
                $this->fail('Invalid tags must be rejected');
            } catch (ValidationException) {
                $this->assertSame('Кухня', $project->fresh()->value);
                $this->assertSame([$tag->id], $project->fresh()->tags->modelKeys());
            }
        }
    }

    public function test_pivot_write_failure_rolls_back_project(): void
    {
        $tag = $this->tag('Bosch');
        DB::unprepared("CREATE TRIGGER fail_tag_insert BEFORE INSERT ON taggables BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        try {
            $this->upsert(['value' => 'Не должно сохраниться', 'tag_ids' => [$tag->id]]);
            $this->fail('Pivot write must fail');
        } catch (QueryException) {
            $this->assertDatabaseCount('mebel_projects', 0);
            $this->assertDatabaseCount('taggables', 0);
        }
    }

    public function test_tag_creation_deduplicates_within_group_and_requires_owner(): void
    {
        $this->upsert(['value' => 'Кухня']);
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn(User::where('email', 'owner@example.com')->first());
        $create = app(CreateTag::class);
        $a = $create(null, ['input' => ['tag_group_id' => 1, 'name' => '  Bosch  ']], $context);
        $b = $create(null, ['input' => ['tag_group_id' => 1, 'name' => 'BOSCH']], $context);
        $c = $create(null, ['input' => ['tag_group_id' => 2, 'name' => 'Bosch']], $context);
        $this->assertSame($a->id, $b->id);
        $this->assertNotSame($a->id, $c->id);
        $this->assertSame('Bosch', $a->name);
        $this->assertDatabaseCount('tags', 2);
        $guest = $this->createMock(GraphQLContext::class);
        $this->expectException(GraphQLException::class);
        $create(null, ['input' => ['tag_group_id' => 1, 'name' => 'Denied']], $guest);
    }

    public function test_invalid_group_and_blank_name_are_rejected(): void
    {
        $this->upsert(['value' => 'Кухня']);
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn(User::where('email', 'owner@example.com')->first());
        foreach ([['tag_group_id' => 999, 'name' => 'Bosch'], ['tag_group_id' => 1, 'name' => '   '], ['tag_group_id' => 1, 'name' => str_repeat('x', 121)]] as $input) {
            try {
                app(CreateTag::class)(null, ['input' => $input], $context);
                $this->fail('Invalid tag must be rejected');
            } catch (ValidationException) {
                $this->assertDatabaseCount('tags', 0);
            }
        }
    }

    public function test_soft_delete_preserves_tags_and_force_delete_detaches(): void
    {
        $tag = $this->tag('Bosch');
        $project = $this->upsert(['value' => 'Кухня', 'tag_ids' => [$tag->id]]);
        $project->delete();
        $this->assertDatabaseCount('taggables', 1);
        $project->restore();
        $this->assertSame([$tag->id], $project->fresh()->tags->modelKeys());
        $project->forceDelete();
        $this->assertDatabaseCount('taggables', 0);
        $this->assertDatabaseCount('tags', 1);
    }

    public function test_complete_project_saves_photos_tags_zero_price_and_ulid_key(): void
    {
        $this->prepareImageSchema();
        $this->upsert(['value' => 'Подготовка']);
        $url = $this->uploadTestImage();
        $tag = $this->tag('Bosch');
        $project = $this->upsert([
            'value' => '  Очень длинное название нового мебельного проекта  ',
            'price' => 0, 'old_price' => 1500.25,
            'tag_ids' => [$tag->id], 'image_urls' => [$url],
        ]);
        $this->assertSame('Очень длинное название нового мебельного проекта', $project->value);
        $this->assertTrue(Str::isUlid($project->key));
        $this->assertSame('0.00', $project->price);
        $this->assertSame('1500.25', $project->old_price);
        $this->assertSame([$tag->id], $project->tags->modelKeys());
        $image = $project->images->sole();
        $this->assertSame('image/png', $image->mime_type);
        $this->assertGreaterThan(0, $image->size);
        $this->assertSame($url, $image->path);
        $this->assertSame(MebelProject::class, $image->parentable_type);
    }

    public function test_invalid_project_fields_are_rejected_before_writes(): void
    {
        foreach ([['value' => '   '], ['value' => str_repeat('a', 256)], ['price' => -1], ['price' => 12.123], ['price' => 10000000000], ['category_id' => 'unknown'], ['is_active' => 'yes'], ['image_urls' => array_fill(0, 9, 'https://example.com/image.jpg')]] as $invalid) {
            try {
                $this->upsert(['value' => 'Кухня', ...$invalid]);
                $this->fail('Invalid project must be rejected');
            } catch (ValidationException) {
                $this->assertDatabaseCount('mebel_projects', 0);
            }
        }
        $this->category->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        $this->upsert(['value' => 'Кухня']);
    }

    public function test_foreign_rubric_is_rejected(): void
    {
        $this->category->rubric->update(['slug' => 'santehnika']);
        $this->expectException(ValidationException::class);
        $this->upsert(['value' => 'Кухня']);
    }

    public function test_untrusted_foreign_and_missing_photos_do_not_save_project(): void
    {
        $this->upsert(['value' => 'Подготовка']);
        Storage::fake('yandex');
        $own = $this->uploadTestImage();
        foreach (['http://127.0.0.1/private', 'file:///etc/passwd', 'https://evil.example/photo.png', str_replace(md5(License::first()->id), md5('other-license'), $own), $own.'?redirect=1', str_replace('.png', 'missing.png', $own)] as $url) {
            try {
                $this->upsert(['value' => 'Не сохранять', 'image_urls' => [$url]]);
                $this->fail('Untrusted or missing photo must be rejected');
            } catch (ValidationException) {
                $this->assertDatabaseCount('mebel_projects', 1);
            }
        }
    }

    public function test_invalid_photo_content_and_oversized_files_are_rejected(): void
    {
        $this->upsert(['value' => 'Подготовка']);
        foreach (['<script>not an image</script>', str_repeat('x', 10 * 1024 * 1024 + 1)] as $content) {
            $url = $this->uploadTestImage($content);
            try {
                $this->upsert(['value' => 'Не сохранять', 'image_urls' => [$url]]);
                $this->fail('Invalid image content must be rejected');
            } catch (ValidationException) {
                $this->assertDatabaseCount('mebel_projects', 1);
            }
        }
    }

    public function test_image_write_failure_rolls_back_project_tags_and_old_photos(): void
    {
        $this->prepareImageSchema();
        $project = $this->upsert(['value' => 'Исходное имя']);
        $url = $this->uploadTestImage();
        $project = $this->upsert(['id' => $project->id, 'value' => 'Исходное имя', 'image_urls' => [$url]]);
        $oldImageId = $project->images->sole()->id;
        $tag = $this->tag('Bosch');
        DB::unprepared("CREATE TRIGGER fail_image_insert BEFORE INSERT ON images BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        try {
            $this->upsert(['id' => $project->id, 'value' => 'Изменено', 'image_urls' => [$url], 'tag_ids' => [$tag->id]]);
            $this->fail('Image write must fail');
        } catch (QueryException) {
            $this->assertSame('Исходное имя', $project->fresh()->value);
            $this->assertSame($oldImageId, $project->fresh()->images->sole()->id);
            $this->assertDatabaseCount('taggables', 0);
        }
    }

    public function test_deleted_slug_is_not_reused(): void
    {
        $first = $this->upsert(['value' => 'Кухня']);
        $first->delete();
        $second = $this->upsert(['value' => 'Кухня']);
        $this->assertNotSame($first->slug, $second->slug);
    }

    public function test_graphql_returns_field_errors_and_accepts_complete_payload(): void
    {
        $this->prepareImageSchema();
        $this->upsert(['value' => 'Подготовка']);
        $this->actingAs(User::where('email', 'owner@example.com')->first(), 'api');
        $query = 'mutation Save($input: UpsertMebelProjectInput!) { upsertMebelProject(input: $input) { id value price tags { id } } }';
        $invalid = $this->postJson('/graphql', ['query' => $query, 'variables' => ['input' => ['category_id' => $this->category->id, 'value' => 'Кухня', 'price' => -1]]]);
        $invalid->assertOk()->assertJsonPath('errors.0.extensions.validation.price.0', 'Цена не может быть отрицательной.');
        $valid = $this->postJson('/graphql', ['query' => $query, 'variables' => ['input' => ['category_id' => $this->category->id, 'value' => 'Из формы', 'price' => 0, 'image_urls' => [$this->uploadTestImage()], 'tag_ids' => [$this->tag('Miele')->id]]]]);
        $valid->assertOk()->assertJsonMissingPath('errors')->assertJsonPath('data.upsertMebelProject.price', 0)->assertJsonCount(1, 'data.upsertMebelProject.tags');
    }

    public function test_edit_payload_updates_existing_project_and_preserves_photos(): void
    {
        $this->prepareImageSchema();
        $project = $this->upsert(['value' => 'Исходное имя']);
        $project = $this->upsert(['id' => $project->id, 'value' => 'Исходное имя', 'image_urls' => [$this->uploadTestImage()], 'completed_at' => '2026-09-14', 'price' => 100, 'old_price' => 120, 'maker' => 'Фабрика', 'tag_ids' => [$this->tag('Bosch')->id]]);
        $imageId = $project->images->sole()->id;
        $updated = $this->upsert([
            'id' => $project->id, 'value' => 'Обновлённая кухня', 'price' => 0,
            'old_price' => null, 'completed_at' => null, 'object_address' => null,
            'maker' => null, 'hardware_brands' => [], 'appliance_brands' => [],
            'tag_ids' => [], 'is_active' => false, 'is_new' => true, 'is_featured' => true,
        ]);
        $this->assertDatabaseCount('mebel_projects', 1);
        $this->assertSame($project->id, $updated->id);
        $this->assertSame($imageId, $updated->images->sole()->id);
        $this->assertSame('0.00', $updated->price);
        $this->assertNull($updated->old_price);
        $this->assertNull($updated->completed_at);
        $this->assertNull($updated->meta);
        $this->assertCount(0, $updated->tags);
        $this->assertFalse($updated->is_active);
        $this->assertTrue($updated->is_featured);
        $this->assertTrue($updated->is_new);
    }

    public function test_delete_mutation_soft_deletes_preserving_photos_tags_and_restore(): void
    {
        $this->prepareImageSchema();
        $project = $this->upsert(['value' => 'Кухня для удаления']);
        $project = $this->upsert(['id' => $project->id, 'value' => $project->value, 'image_urls' => [$this->uploadTestImage()], 'tag_ids' => [$this->tag('Bosch')->id], 'maker' => 'Фабрика']);
        $imageId = $project->images->sole()->id;
        $owner = User::where('email', 'owner@example.com')->first();
        $deleted = $this->deleteAs($project->id, $owner);
        $this->assertTrue($deleted->trashed());
        $this->assertNull(MebelProject::find($project->id));
        $this->assertDatabaseCount('mebel_projects', 1);
        $this->assertSame($imageId, $deleted->images->sole()->id);
        $this->assertCount(1, $deleted->tags);
        $this->assertSame('Фабрика', $deleted->meta['maker']);
        $this->assertTrue($deleted->is_active);
        $deleted->restore();
        $restored = MebelProject::findOrFail($project->id);
        $this->assertSame($imageId, $restored->images->sole()->id);
        $this->assertCount(1, $restored->tags);
    }

    public function test_delete_retries_are_idempotent_and_invalidate_the_site_cache(): void
    {
        $project = $this->upsert(['value' => 'Кухня']);
        $owner = User::where('email', 'owner@example.com')->first();
        Cache::tags(["license:{$project->license_id}"])->put('delete-test', 'stale');
        $deleted = $this->deleteAs($project->id, $owner);
        $timestamp = $deleted->deleted_at->toISOString();
        $this->assertNull(Cache::tags(["license:{$project->license_id}"])->get('delete-test'));
        $this->travel(2)->seconds();
        $again = $this->deleteAs($project->id, $owner);
        $this->assertSame($timestamp, $again->deleted_at->toISOString());
        $this->assertDatabaseCount('mebel_projects', 1);
    }

    public function test_delete_rejects_foreign_global_and_unknown_projects(): void
    {
        $own = $this->upsert(['value' => 'Своя кухня']);
        $owner = User::where('email', 'owner@example.com')->first();
        $foreign = $this->upsert(['value' => 'Чужая кухня']);
        $other = User::create(['name' => 'Other', 'email' => 'other-delete@example.com', 'password' => bcrypt('test-password')]);
        $license = License::create(['user_id' => $other->id, 'domain' => 'other-delete.example.com', 'name' => 'Other', 'is_active' => true]);
        $foreign->update(['license_id' => $license->id]);
        $global = $this->upsert(['value' => 'Общая кухня']);
        $global->update(['license_id' => null]);
        foreach ([$foreign->id, $global->id, (string) Str::ulid()] as $id) {
            try {
                $this->deleteAs($id, $owner);
                $this->fail('Unauthorized deletion must be rejected');
            } catch (GraphQLException) {
                $this->assertSame(3, MebelProject::count());
            }
        }
        $this->assertNotNull(MebelProject::find($own->id));
    }

    public function test_delete_graphql_requires_authentication_and_returns_deleted_id(): void
    {
        $project = $this->upsert(['value' => 'Кухня']);
        $query = 'mutation Delete($id: ID!) { deleteMebelProject(id: $id) { id } }';
        $this->postJson('/graphql', ['query' => $query, 'variables' => ['id' => $project->id]])
            ->assertJsonStructure(['errors' => [['message']]]);
        $this->assertNotNull(MebelProject::find($project->id));
        $this->actingAs(User::where('email', 'owner@example.com')->first(), 'api');
        $this->postJson('/graphql', ['query' => $query, 'variables' => ['id' => $project->id]])
            ->assertOk()->assertJsonMissingPath('errors')->assertJsonPath('data.deleteMebelProject.id', $project->id);
        $this->assertSoftDeleted('mebel_projects', ['id' => $project->id]);
    }

    public function test_delete_rejects_invalid_id_and_unlicensed_user(): void
    {
        $project = $this->upsert(['value' => 'Кухня']);
        $owner = User::where('email', 'owner@example.com')->first();
        try {
            $this->deleteAs('invalid-id', $owner);
            $this->fail('Invalid ID must be rejected');
        } catch (ValidationException) {
            $this->assertNotNull(MebelProject::find($project->id));
        }
        $unlicensed = User::create(['name' => 'No license', 'email' => 'no-license@example.com', 'password' => bcrypt('test-password')]);
        $this->expectException(GraphQLException::class);
        $this->deleteAs($project->id, $unlicensed);
    }

    private function deleteAs(string $id, ?User $user): MebelProject
    {
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn($user);

        return app(DeleteMebelProject::class)(null, ['id' => $id], $context);
    }

    private function prepareImageSchema(): void
    {
        (require base_path('../leget-db/database/migrations/2026_05_09_000004_create_images_table.php'))->up();
    }

    private function uploadTestImage(?string $content = null): string
    {
        Storage::fake('yandex');
        config(['filesystems.disks.yandex.endpoint' => 'https://storage.yandexcloud.net', 'filesystems.disks.yandex.bucket' => 'leget-main']);
        $key = 'mebel/'.md5(License::first()->id).'/'.str_repeat('a', 40).'.png';
        $content ??= base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aSgAAAABJRU5ErkJggg==');
        Storage::disk('yandex')->put($key, $content);

        return 'https://storage.yandexcloud.net/leget-main/'.$key;
    }

    private function tag(string $name): Tag
    {
        return Tag::create(['tag_group_id' => 1, 'name' => $name, 'normalized_name' => Str::lower($name)]);
    }

    private function upsert(array $input): MebelProject
    {
        $user = User::firstOrCreate(
            ['email' => 'owner@example.com'],
            ['name' => 'Owner', 'password' => bcrypt('password')],
        );

        License::firstOrCreate(
            ['domain' => 'passport.example.com'],
            ['user_id' => $user->id, 'name' => 'Test Site', 'template_id' => 1, 'is_active' => true, 'status' => 'active'],
        );

        $request = Request::create('/graphql', 'POST');
        $request->headers->set('X-Forwarded-Host', 'passport.example.com');

        $context = $this->createMock(GraphQLContext::class);
        $context->method('request')->willReturn($request);
        $context->method('user')->willReturn($user);

        $project = ($this->mutation)(
            null,
            ['input' => array_merge(['category_id' => $this->category->id], $input)],
            $context,
            $this->createMock(ResolveInfo::class),
        );

        return $project->fresh();
    }
}
