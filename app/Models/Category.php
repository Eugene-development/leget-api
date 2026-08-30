<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['key', 'rubric_id', 'is_active', 'is_enabled', 'value', 'slug', 'description', 'bg', 'seo_title', 'seo_description', 'seo_keywords', 'sort_order', 'created_by', 'updated_by', 'deleted_by'])]
class Category extends Model
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
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // ─────────────────────────────────────────────
    // Отношения
    // ─────────────────────────────────────────────

    /**
     * Получить рубрику, к которой принадлежит категория (Many to One).
     */
    public function rubric(): BelongsTo
    {
        return $this->belongsTo(Rubric::class, 'rubric_id');
    }

    public function brands(): HasMany
    {
        return $this->hasMany(CatalogBrand::class)->orderBy('sort_order')->orderBy('slug');
    }

    /**
     * Получить все проекты категории (One to Many).
     */
    public function mebelProjects(): HasMany
    {
        return $this->hasMany(MebelProject::class, 'category_id');
    }

    /**
     * Получить все изображения категории (полиморфное One to Many).
     */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'parentable')->orderBy('sort_order');
    }

    // ─────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────

    /**
     * Фильтр: только активные категории.
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

    /**
     * Фильтр: категории конкретной рубрики.
     */
    public function scopeByRubric($query, string $rubricId)
    {
        return $query->where('rubric_id', $rubricId);
    }
}
