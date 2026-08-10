<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Дизайн-система: внешний вид, который шаблон сам по себе не задаёт.
 *
 * Шаблон (Promo-1/2/3) отвечает за структуру — страницы, блоки, форму данных.
 * Дизайн-система отвечает за палитру, типографику, ритм и пластику блоков.
 * Обе связи многие-ко-многим: у шаблона несколько систем, у системы несколько шаблонов;
 * версия компонента принадлежит одной или нескольким системам.
 *
 * Модель целиком: docs/architecture/design-systems.md
 */
#[Fillable(['name', 'slug', 'description', 'is_base'])]
class DesignSystem extends Model
{
    use HasUlids;

    /**
     * Slug Базовой системы — песочницы для легаси и версий в разработке.
     */
    public const BASE_SLUG = 'base';

    /**
     * Таблица заявленной области: для каких шаблонов система предназначена.
     */
    public const TEMPLATE_PIVOT = 'design_system_template';

    /**
     * Тип первичного ключа — строка (ULID).
     */
    protected $keyType = 'string';

    /**
     * Первичный ключ не является автоинкрементным.
     */
    public $incrementing = false;

    /**
     * Приведение типов атрибутов.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_base' => 'boolean',
        ];
    }

    /**
     * Версии компонентов, принадлежащие системе.
     */
    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(
            ComponentVariant::class,
            'component_variant_design_system',
            'design_system_id',
            'component_variant_id',
        )->withTimestamps();
    }

    /**
     * Только Базовая система (песочница). Ровно одна на всю платформу.
     */
    public function scopeBase($query)
    {
        return $query->where('is_base', true);
    }

    /**
     * Заявленная область: строки design_system_template этой системы.
     *
     * Не Eloquent-связь: у шаблонов нет таблицы (они живут в config/templates.php),
     * а pivot имеет составной первичный ключ — Eloquent с таким работает плохо.
     * Читаем напрямую.
     *
     * @return \Illuminate\Support\Collection<int, object{template_id: int, is_published: bool}>
     */
    public function templateScopes(): Collection
    {
        return DB::table(self::TEMPLATE_PIVOT)
            ->where('design_system_id', $this->id)
            ->orderBy('template_id')
            ->get(['template_id', 'is_published'])
            ->map(fn ($row) => (object) [
                'template_id'  => (int) $row->template_id,
                'is_published' => (bool) $row->is_published,
            ]);
    }
}
