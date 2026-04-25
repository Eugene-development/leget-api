<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'domain', 'template_id', 'is_active', 'status', 'name', 'meta_description', 'header_data', 'footer_data'])]
class License extends Model
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
     * Получить владельца лицензии.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить все страницы лицензии.
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /**
     * Приведение типов атрибутов.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active'   => 'boolean',
            'header_data' => 'array',
            'footer_data' => 'array',
        ];
    }
}
