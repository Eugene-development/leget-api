<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['license_id', 'slug', 'seo_title', 'seo_description', 'seo_keywords'])]
class Page extends Model
{
    protected function casts(): array
    {
        return ['component_order' => 'array'];
    }

    /**
     * Получить лицензию, к которой принадлежит страница.
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * Получить компоненты страницы.
     */
    public function pageComponents(): HasMany
    {
        return $this->hasMany(PageComponent::class);
    }
}
