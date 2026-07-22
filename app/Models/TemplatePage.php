<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Страница шаблона в глобальном каталоге (даёт странице порядковый номер артикула).
 *
 * Соответствует ключу в config/templates.php['pages'] для данного template_id.
 */
#[Fillable(['template_id', 'slug', 'page_number', 'name'])]
class TemplatePage extends Model
{
    use HasUlids;

    /**
     * Тип первичного ключа — строка (ULID).
     */
    protected $keyType = 'string';

    /**
     * Первичный ключ не является автоинкрементным.
     */
    public $incrementing = false;

    /**
     * Компоненты каталога, принадлежащие этой странице шаблона.
     */
    public function components(): HasMany
    {
        return $this->hasMany(Component::class, 'page_id');
    }
}
