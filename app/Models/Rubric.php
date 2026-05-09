<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['key', 'is_active', 'value', 'slug', 'description', 'seo_title', 'seo_description', 'seo_keywords', 'sort_order'])]
class Rubric extends Model
{
    use HasUlids, SoftDeletes;

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
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // ─────────────────────────────────────────────
    // Отношения
    // ─────────────────────────────────────────────

    /**
     * Получить все категории рубрики (One to Many).
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'rubric_id');
    }

    /**
     * Получить все изображения рубрики (полиморфное One to Many).
     */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'parentable')->orderBy('sort_order');
    }

    // ─────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────

    /**
     * Фильтр: только активные рубрики.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Сортировка по полю sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
