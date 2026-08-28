<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['key', 'category_id', 'license_id', 'is_active', 'value', 'slug', 'description', 'short_description', 'completed_at', 'object_address', 'price', 'old_price', 'seo_title', 'seo_description', 'seo_keywords', 'meta', 'sort_order', 'is_featured', 'is_new', 'created_by', 'updated_by', 'deleted_by'])]
class MebelProject extends Model
{
    use HasUlids, SoftDeletes;

    /**
     * Имя таблицы в базе данных.
     */
    protected $table = 'mebel_projects';

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
            'is_active'   => 'boolean',
            'is_featured' => 'boolean',
            'is_new'      => 'boolean',
            'price'       => 'decimal:2',
            'old_price'   => 'decimal:2',
            'sort_order'  => 'integer',
            'meta'        => 'array',
            // Дата, а не datetime: сдача объекта — календарный день, времени
            // у неё нет, и хранить его значит показывать «14.03.2026 00:00».
            'completed_at' => 'date',
        ];
    }

    // ─────────────────────────────────────────────
    // Отношения
    // ─────────────────────────────────────────────

    /**
     * Получить категорию, к которой принадлежит проект (Many to One).
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Получить все изображения проекта (полиморфное One to Many).
     */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'parentable')->orderBy('sort_order');
    }

    // ─────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────

    /**
     * Фильтр: только активные проекты.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Фильтр: только избранные проекты.
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Фильтр: только новинки.
     */
    public function scopeNew($query)
    {
        return $query->where('is_new', true);
    }

    /**
     * Сортировка по полю sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Фильтр: проекты конкретной категории.
     */
    public function scopeByCategory($query, string $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }
}
