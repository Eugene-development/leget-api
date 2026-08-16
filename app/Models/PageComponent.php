<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Экземпляр блока на конкретном сайте.
 *
 * `type` — технический ключ каталога, человеку он больше не показывается: имя
 * блока в интерфейсе даёт `label`, а назначение — `role_slug` из справочника
 * config/component_roles.php. Каталог хранит конструкцию, экземпляр хранит смысл;
 * см. docs/architecture/component-morphotypes.md.
 */
#[Fillable(['page_id', 'license_id', 'type', 'data', 'is_active', 'sort_order', 'label', 'role_slug'])]
class PageComponent extends Model
{
    use HasUlids;

    /**
     * Тип первичного ключа — строка (ULID).
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Первичный ключ не является автоинкрементным.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Получить страницу, к которой принадлежит компонент.
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Получить лицензию, к которой принадлежит компонент.
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * Приведение типов атрибутов.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data'      => 'array',
            'is_active' => 'boolean',
        ];
    }
}
