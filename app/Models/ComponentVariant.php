<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Версия компонента (v1…v4) из каталога.
 *
 * Полный артикул хранится в колонке article: {template}.{page}.{component}.{version}.
 *
 * У версии две независимые характеристики, которые легко перепутать:
 *   - статус в жизненном цикле (draft → active → legacy) — колонка status;
 *   - принадлежность дизайн-системам — связь designSystems().
 * Статус не говорит, в какой системе версия лежит, и наоборот: в Базовой могут
 * одновременно лежать draft (новое) и active (легаси, работающее на живых сайтах).
 *
 * Третья, тоже независимая, — конструкция: колонка morph и связь roles().
 * Морфотип отвечает «что блок ЕСТЬ по устройству», роли — «что он МОЖЕТ
 * исполнить». Обе величины общие для всех сайтов; чем блок СТАЛ у конкретного
 * тенанта, хранится в page_components (label + role_slug) и здесь не отражается.
 * Именно поэтому версии одного компонента различаются морфотипами: v1 у Stage —
 * `plain : cards.grid.3-6.icon`, v2 — `aside : list.stack.3-6.icon/ord`.
 *
 * См. docs/architecture/component-lifecycle.md
 * и docs/architecture/component-morphotypes.md
 */
#[Fillable(['component_id', 'version', 'name', 'article', 'status', 'morph'])]
class ComponentVariant extends Model
{
    use HasUlids;

    /**
     * Ещё не выпущена: не предлагается в переключателе и не рендерится.
     */
    public const STATUS_DRAFT = 'draft';

    /**
     * В обращении: предлагается и рендерится.
     */
    public const STATUS_ACTIVE = 'active';

    /**
     * Выводится из обращения: НЕ предлагается, но продолжает рендериться там,
     * где уже выбрана. Именно это несовпадение делает вывод безопасным.
     */
    public const STATUS_LEGACY = 'legacy';

    /**
     * Тип первичного ключа — строка (ULID).
     */
    protected $keyType = 'string';

    /**
     * Первичный ключ не является автоинкрементным.
     */
    public $incrementing = false;

    /**
     * Компонент каталога, которому принадлежит версия.
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'component_id');
    }

    /**
     * Дизайн-системы, которым принадлежит версия (одна или несколько).
     *
     * Версия в Базовой системе — ещё не приписанная. Членство в Базовой исключающее:
     * попала хотя бы в одну настоящую систему — из Базовой удаляется. На уровне схемы
     * это не выражено, за соблюдением следит ComponentRegistrar.
     */
    public function designSystems(): BelongsToMany
    {
        return $this->belongsToMany(
            DesignSystem::class,
            'component_variant_design_system',
            'component_variant_id',
            'design_system_id',
        )->withTimestamps();
    }

    /**
     * Роли, которые способна исполнить конструкция версии.
     *
     * HasMany, а не BelongsToMany: справочника ролей в БД нет, целевой модели для
     * pivot-связи не существует — slug'и живут в config/component_roles.php.
     * Обоснование в шапке миграции create_component_variant_role_table.
     *
     * Связь для чтения. Синхронизацию делает ComponentRegistrar::applyMorphotype().
     */
    public function roles(): HasMany
    {
        return $this->hasMany(ComponentVariantRole::class, 'component_variant_id');
    }

    /**
     * Плоский список slug'ов ролей — то, что нужно фронту и GraphQL.
     *
     * @return list<string>
     */
    public function getRoleSlugsAttribute(): array
    {
        return $this->roles->pluck('role_slug')->values()->all();
    }
}
