<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['key', 'is_active', 'hash', 'filename', 'original_name', 'mime_type', 'size', 'path', 'alt', 'caption', 'parentable_type', 'parentable_id', 'sort_order'])]
class Image extends Model
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
     * Поля, доступные для массового заполнения
     */
    protected $fillable = [
        'key',
        'is_active',
        'hash',
        'filename',
        'original_name',
        'mime_type',
        'size',
        'path',
        'parentable_type',
        'parentable_id',
        'sort_order',
    ];

    /**
     * Аксессор для получения полного URL изображения.
     */
    public function getUrlAttribute(): ?string
    {
        if (!$this->path) {
            return null;
        }

        if (str_starts_with($this->path, 'http')) {
            return $this->path;
        }

        $endpoint = config('filesystems.disks.s3.endpoint', 'https://storage.yandexcloud.net');
        $bucket = config('filesystems.disks.s3.bucket', 'leget-main');

        return rtrim($endpoint, '/') . '/' . $bucket . '/' . ltrim($this->path, '/');
    }

    /**
     * Добавляемые поля при преобразовании в массив/JSON.
     */
    protected $appends = ['url'];

    /**
     * Приведение типов атрибутов.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'size'       => 'integer',
            'sort_order' => 'integer',
        ];
    }

    // ─────────────────────────────────────────────
    // Отношения
    // ─────────────────────────────────────────────

    /**
     * Получить родительскую сущность изображения (полиморфное).
     *
     * Может быть: Rubric, Category, MebelProject — или любой будущей сущностью.
     */
    public function parentable(): MorphTo
    {
        return $this->morphTo();
    }

    // ─────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────

    /**
     * Фильтр: только активные изображения.
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
