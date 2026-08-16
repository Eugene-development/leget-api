<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ComponentRegistrar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Морфотип и роли версии компонента.
 *
 * Главное, что здесь проверяется, — ПОЛНАЯ пересинхронизация ролей. Источник
 * истины — config/component_morphotypes.php, и роль, убранная из конфига, обязана
 * исчезнуть из таблицы. Внешнего ключа на справочник нет (он живёт в конфиге),
 * поэтому единственная защита от накопления мусора — код регистратора и этот тест.
 *
 * Второе — асимметрия: роли синхронизируются полностью, а морфотип затирается
 * только непустым значением. Выпадение записи из конфига не должно молча обнулять
 * уже выписанную конструкцию.
 *
 * Схема повторяет canonical-миграции ms/leget-db/database/migrations/2026_08_16_*.
 */
final class ComponentRegistrarMorphotypeTest extends TestCase
{
    use RefreshDatabase;

    private ComponentRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCatalogSchema();

        $this->registrar = app(ComponentRegistrar::class);
    }

    public function test_morphotype_and_roles_are_written(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'Incentives');
        $variant   = $this->registrar->ensureVariant($component, 2);

        $this->registrar->applyMorphotype(
            $variant,
            'split(photo).tabs : list.stack.=4.none',
            ['benefits', 'services', 'steps'],
        );

        $variant->refresh()->load('roles');

        $this->assertSame('split(photo).tabs : list.stack.=4.none', $variant->morph);
        $this->assertEqualsCanonicalizing(['benefits', 'services', 'steps'], $variant->role_slugs);
    }

    public function test_roles_removed_from_config_disappear(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'Incentives');
        $variant   = $this->registrar->ensureVariant($component, 1);

        $this->registrar->applyMorphotype($variant, 'plain : cards.grid.3-6.icon', ['benefits', 'services', 'steps']);
        $this->registrar->applyMorphotype($variant, 'plain : cards.grid.3-6.icon', ['benefits']);

        $this->assertEqualsCanonicalizing(['benefits'], $variant->refresh()->load('roles')->role_slugs);
    }

    public function test_repeat_run_does_not_duplicate_roles(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'Stage');
        $variant   = $this->registrar->ensureVariant($component, 1);

        $this->registrar->applyMorphotype($variant, 'plain : cards.grid.3-6.icon', ['benefits', 'steps']);
        $this->registrar->applyMorphotype($variant, 'plain : cards.grid.3-6.icon', ['benefits', 'steps']);

        // Составной первичный ключ упал бы на дубле раньше этой проверки, но
        // ассерт оставлен: идемпотентность сеятеля — заявленное свойство, а не
        // побочный эффект индекса.
        $this->assertCount(2, $variant->refresh()->load('roles')->roles);
    }

    public function test_null_morph_does_not_erase_existing(): void
    {
        $component = $this->registrar->ensureComponent(1, '/', 'Equipment');
        $variant   = $this->registrar->ensureVariant($component, 1);

        $this->registrar->applyMorphotype($variant, 'plain : cards.mosaic.=5.photo', ['gallery']);
        $this->registrar->applyMorphotype($variant, null, ['gallery']);

        $this->assertSame('plain : cards.mosaic.=5.photo', $variant->refresh()->morph);
    }

    public function test_versions_of_one_component_carry_different_morphotypes(): void
    {
        // Ровно то различие, ради которого морфотип принадлежит версии, а не
        // компоненту: v1 у Stage — сетка карточек, v2 — таймлайн с залипающей
        // колонкой. Колонка на `components` схлопнула бы их в одну запись.
        $component = $this->registrar->ensureComponent(1, '/', 'Stage');

        $v1 = $this->registrar->ensureVariant($component, 1);
        $v2 = $this->registrar->ensureVariant($component, 2);

        $this->registrar->applyMorphotype($v1, 'plain : cards.grid.3-6.icon', ['benefits']);
        $this->registrar->applyMorphotype($v2, 'aside : list.stack.3-6.icon/ord', ['steps']);

        $this->assertNotSame($v1->refresh()->morph, $v2->refresh()->morph);
    }

    /**
     * Каждая роль из сгенерированного конфига существует в справочнике.
     *
     * Тот же инвариант проверяет `component-catalog:seed` перед записью, но здесь
     * он ловится на CI — до того, как рассинхрон доедет до машины с базой.
     */
    public function test_every_generated_role_exists_in_the_reference(): void
    {
        $book    = config('component_roles.roles', []);
        $unknown = [];

        foreach (config('component_morphotypes', []) as $pages) {
            foreach ($pages as $types) {
                foreach ($types as $versions) {
                    foreach ($versions as $entry) {
                        foreach (($entry['roles'] ?? []) as $slug) {
                            if (! isset($book[$slug])) {
                                $unknown[$slug] = true;
                            }
                        }
                    }
                }
            }
        }

        $this->assertSame([], array_keys($unknown), 'config/component_roles.php отстал от config/component_morphotypes.php');
    }

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
            $table->string('morph', 160)->nullable();
            $table->timestamps();
            $table->unique(['component_id', 'version']);
        });

        Schema::create('component_variant_role', function (Blueprint $table) {
            $table->char('component_variant_id', 26);
            $table->string('role_slug', 48);
            $table->timestamps();
            $table->primary(['component_variant_id', 'role_slug'], 'cvr_primary');
            $table->index('role_slug');
        });

        Schema::create('design_systems', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_base')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('component_variant_design_system', function (Blueprint $table) {
            $table->char('component_variant_id', 26);
            $table->char('design_system_id', 26);
            $table->timestamps();
            $table->primary(['component_variant_id', 'design_system_id'], 'cvds_primary');
        });
    }
}
