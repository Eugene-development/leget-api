<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL\Queries;

use App\GraphQL\Queries\DesignSystems;
use App\Models\DesignSystem;
use App\Services\ComponentRegistrar;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

/**
 * Резолвер списка дизайн-систем.
 *
 * Главное поведение: у Базовой системы нет заявленной области, поэтому её
 * относимость к шаблону ВЫЧИСЛЯЕТСЯ — она предлагается на шаблоне ровно до тех пор,
 * пока в ней остаются неприписанные версии компонентов этого шаблона. Когда все
 * разъехались по настоящим системам, Базовая перестаёт предлагаться сама.
 */
final class DesignSystemsTest extends TestCase
{
    use RefreshDatabase;

    private DesignSystems $resolver;

    private ComponentRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCatalogSchema();

        $this->resolver  = app(DesignSystems::class);
        $this->registrar = app(ComponentRegistrar::class);
    }

    public function test_base_is_offered_while_it_holds_variants_of_the_template(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'HeroMain');
        $this->registrar->ensureVariant($component, 1);

        $this->assertSame(['Базовая'], $this->resolve(1));
    }

    public function test_base_stops_being_offered_when_template_is_fully_migrated(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'HeroMain');
        $variant   = $this->registrar->ensureVariant($component, 1);

        $arDeco = DesignSystem::create(['name' => 'Ар-деко', 'slug' => 'ar-deco']);
        DB::table(DesignSystem::TEMPLATE_PIVOT)->insert([
            'design_system_id' => $arDeco->id,
            'template_id'      => 1,
            'is_published'     => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->registrar->assignToDesignSystem($variant, $arDeco);

        $this->assertSame(['Ар-деко'], $this->resolve(1), 'Базовая должна исчезнуть сама');
    }

    public function test_base_is_offered_per_template_independently(): void
    {
        // Promo-1 отрефакторен целиком, Promo-2 ещё нет.
        $c1 = $this->registrar->ensureComponent(1, '/', 'HeroMain');
        $v1 = $this->registrar->ensureVariant($c1, 1);
        $c2 = $this->registrar->ensureComponent(2, '/', 'HeroMain');
        $this->registrar->ensureVariant($c2, 1);

        $arDeco = DesignSystem::create(['name' => 'Ар-деко', 'slug' => 'ar-deco']);
        $this->registrar->assignToDesignSystem($v1, $arDeco);

        $this->assertSame([], $this->resolve(1), 'Ар-деко не заявлена для Promo-1');
        $this->assertSame(['Базовая'], $this->resolve(2), 'Promo-2 ещё в песочнице');
    }

    public function test_declared_system_is_returned_even_without_variants(): void
    {
        $minimal = DesignSystem::create(['name' => 'Минимал', 'slug' => 'minimal']);
        DB::table(DesignSystem::TEMPLATE_PIVOT)->insert([
            'design_system_id' => $minimal->id,
            'template_id'      => 3,
            'is_published'     => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Заявленная область не требует покрытия: система объявлена и потому видна,
        // а готовность к выбору — отдельный флаг is_published.
        $this->assertSame(['Минимал'], $this->resolve(3));
    }

    public function test_base_goes_first_in_the_list(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'HeroMain');
        $this->registrar->ensureVariant($component, 1);

        $arDeco = DesignSystem::create(['name' => 'Ар-деко', 'slug' => 'ar-deco']);
        DB::table(DesignSystem::TEMPLATE_PIVOT)->insert([
            'design_system_id' => $arDeco->id,
            'template_id'      => 1,
            'is_published'     => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->assertSame(['Базовая', 'Ар-деко'], $this->resolve(1));
    }

    public function test_without_template_filter_all_systems_are_returned(): void
    {
        $this->registrar->ensureBaseDesignSystem();
        DesignSystem::create(['name' => 'Ар-деко', 'slug' => 'ar-deco']);

        $names = ($this->resolver)(null, [], $this->context(), $this->info())->pluck('name')->all();

        $this->assertSame(['Базовая', 'Ар-деко'], $names);
    }

    /**
     * @return array<int, string>
     */
    private function resolve(int $templateId): array
    {
        return ($this->resolver)(null, ['template_id' => $templateId], $this->context(), $this->info())
            ->pluck('name')
            ->all();
    }

    private function context(): GraphQLContext
    {
        return $this->createMock(GraphQLContext::class);
    }

    private function info(): ResolveInfo
    {
        return $this->createMock(ResolveInfo::class);
    }

    /**
     * Таблицы каталога и дизайн-систем — копия canonical-миграций leget-db.
     */
    private function createCatalogSchema(): void
    {
        Schema::create('template_pages', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->unsignedInteger('template_id');
            $table->string('slug');
            $table->integer('page_number');
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['template_id', 'slug']);
            $table->unique(['template_id', 'page_number']);
        });

        Schema::create('components', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->unsignedInteger('template_id');
            $table->char('page_id', 26);
            $table->string('type');
            $table->integer('component_number');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['template_id', 'page_id', 'type']);
            $table->unique(['template_id', 'page_id', 'component_number']);
        });

        Schema::create('component_variants', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('component_id', 26);
            $table->integer('version');
            $table->string('name')->nullable();
            $table->string('article')->unique();
            $table->string('status', 16)->default('draft');
            $table->timestamps();
            $table->unique(['component_id', 'version']);
        });

        Schema::create('design_systems', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_base')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('design_system_template', function (Blueprint $table) {
            $table->char('design_system_id', 26);
            $table->unsignedInteger('template_id');
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->primary(['design_system_id', 'template_id']);
        });

        Schema::create('component_variant_design_system', function (Blueprint $table) {
            $table->char('component_variant_id', 26);
            $table->char('design_system_id', 26);
            $table->timestamps();
            $table->primary(['component_variant_id', 'design_system_id'], 'cvds_primary');
        });
    }
}
