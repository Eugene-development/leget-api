<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Каталожный компонент (дизайн-блок шаблона).
 *
 * Групповой артикул (3 сегмента): {template_id}.{page.page_number}.{component_number}.
 * Полный 4-сегментный артикул живёт на ComponentVariant.
 */
#[Fillable(['template_id', 'page_id', 'type', 'component_number', 'name', 'is_active'])]
class Component extends Model
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
     * Страница шаблона, на которой размещён компонент.
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(TemplatePage::class, 'page_id');
    }

    /**
     * Цветовые схемы (варианты v1…v4) компонента.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ComponentVariant::class, 'component_id');
    }

    /**
     * Приведение типов атрибутов.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Групповой артикул компонента: {template}.{page_number}.{component_number}.
     *
     * Полный артикул (с цветовой схемой) — на ComponentVariant.
     */
    public function getArticleAttribute(): string
    {
        $pageNumber = $this->page?->page_number
            ?? TemplatePage::where('id', $this->page_id)->value('page_number');

        return "{$this->template_id}.{$pageNumber}.{$this->component_number}";
    }
}
