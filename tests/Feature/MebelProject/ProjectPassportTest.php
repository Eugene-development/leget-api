<?php

declare(strict_types=1);

namespace Tests\Feature\MebelProject;

use App\GraphQL\Mutations\UpsertMebelProject;
use App\Models\Category;
use App\Models\License;
use App\Models\MebelProject;
use App\Models\Rubric;
use App\Models\User;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
