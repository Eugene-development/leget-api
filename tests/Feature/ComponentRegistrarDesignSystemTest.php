<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ComponentVariant;
use App\Models\DesignSystem;
use App\Services\ComponentRegistrar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Привязка версий компонентов к дизайн-системам при регистрации в каталоге.
 *
 * Главное, что здесь проверяется, — исключающее членство в Базовой: версия может
 * принадлежать нескольким настоящим системам, но одновременно быть «ещё в песочнице»
 * и «уже в продукте» нельзя. На уровне схемы это не выражено (нужен был бы триггер),
 * поэтому единственная защита — код регистратора и этот тест.
 *
 * Схема повторяет canonical-миграции ms/leget-db/database/migrations/2026_08_08_*.
 */
final class ComponentRegistrarDesignSystemTest extends TestCase
{
    use RefreshDatabase;

    private ComponentRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCatalogSchema();

        $this->registrar = app(ComponentRegistrar::class);
    }

    public function test_new_variant_lands_in_base_design_system(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'HeroMain');
        $variant   = $this->registrar->ensureVariant($component, 1);

        $systems = $variant->designSystems()->get();

        $this->assertCount(1, $systems);
        $this->assertTrue($systems->first()->is_base);
        $this->assertSame('1.1.1.1', $variant->article);
    }

    public function test_new_variant_is_active_by_default(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'HeroMain');

        $this->assertSame(
            ComponentVariant::STATUS_ACTIVE,
            $this->registrar->ensureVariant($component, 1)->status,
        );

        $this->assertSame(
            ComponentVariant::STATUS_DRAFT,
            $this->registrar->ensureVariant($component, 2, null, ComponentVariant::STATUS_DRAFT)->status,
        );
    }

    public function test_assigning_real_system_removes_variant_from_base(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'HeroMain');
        $variant   = $this->registrar->ensureVariant($component, 1);

        $arDeco = DesignSystem::create(['name' => 'Ар-деко', 'slug' => 'ar-deco']);

        $this->registrar->assignToDesignSystem($variant, $arDeco);

        $slugs = $variant->designSystems()->pluck('slug')->all();

        $this->assertSame(['ar-deco'], $slugs, 'Версия должна уйти из Базовой');
    }

    public function test_variant_may_belong_to_several_real_systems(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'HeroMain');
        $variant   = $this->registrar->ensureVariant($component, 1);

        $arDeco  = DesignSystem::create(['name' => 'Ар-деко', 'slug' => 'ar-deco']);
        $minimal = DesignSystem::create(['name' => 'Минимал', 'slug' => 'minimal']);

        $this->registrar->assignToDesignSystem($variant, $arDeco);
        $this->registrar->assignToDesignSystem($variant, $minimal);

        $slugs = $variant->designSystems()->pluck('slug')->sort()->values()->all();

        $this->assertSame(['ar-deco', 'minimal'], $slugs);
    }

    public function test_assigning_same_system_twice_is_idempotent(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'HeroMain');
        $variant   = $this->registrar->ensureVariant($component, 1);
        $arDeco    = DesignSystem::create(['name' => 'Ар-деко', 'slug' => 'ar-deco']);

        $this->registrar->assignToDesignSystem($variant, $arDeco);
        $this->registrar->assignToDesignSystem($variant, $arDeco);

        $this->assertSame(1, $variant->designSystems()->count());
    }

    public function test_base_system_cannot_be_assigned_as_a_destination(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'HeroMain');
        $variant   = $this->registrar->ensureVariant($component, 1);
        $base      = $this->registrar->ensureBaseDesignSystem();

        $this->expectException(InvalidArgumentException::class);

        $this->registrar->assignToDesignSystem($variant, $base);
    }

    public function test_reregistering_assigned_variant_does_not_return_it_to_base(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'HeroMain');
        $variant   = $this->registrar->ensureVariant($component, 1);
        $arDeco    = DesignSystem::create(['name' => 'Ар-деко', 'slug' => 'ar-deco']);

        $this->registrar->assignToDesignSystem($variant, $arDeco);

        // Повторный прогон seed'а каталога не должен откатывать проделанный рефакторинг.
        $this->registrar->ensureVariant($component, 1);

        $this->assertSame(['ar-deco'], $variant->designSystems()->pluck('slug')->all());
    }

    public function test_orphaned_variant_is_returned_to_base_on_reregistration(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'HeroMain');
        $variant   = $this->registrar->ensureVariant($component, 1);

        // Версия, созданная до появления дизайн-систем: не принадлежит ничему.
        $variant->designSystems()->detach();
        $this->assertSame(0, $variant->designSystems()->count());

        $this->registrar->ensureVariant($component, 1);

        $this->assertTrue($variant->designSystems()->first()->is_base);
    }

    public function test_only_one_base_system_can_exist(): void
    {
        $first  = $this->registrar->ensureBaseDesignSystem();
        $second = $this->registrar->ensureBaseDesignSystem();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DesignSystem::query()->base()->count());
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
            // nullable + unique: у Базовой true, у остальных NULL (не false!).
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
