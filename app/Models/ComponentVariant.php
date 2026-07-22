<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Версия компонента (v1…v4) из каталога.
 *
 * Полный артикул хранится в колонке article: {template}.{page}.{component}.{version}.
 */
#[Fillable(['component_id', 'version', 'name', 'article'])]
class ComponentVariant extends Model
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
     * Компонент каталога, которому принадлежит версия.
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'component_id');
    }
}
