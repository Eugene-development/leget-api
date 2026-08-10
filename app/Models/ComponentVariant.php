<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
 * См. docs/architecture/component-lifecycle.md
 */
#[Fillable(['component_id', 'version', 'name', 'article', 'status'])]
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
}
