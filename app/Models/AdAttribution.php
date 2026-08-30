<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Рекламная атрибуция клиента: первое и последнее касание.
 *
 * `$fillable` намеренно НЕ содержит `first_*`: первое касание пишется один раз
 * и только через `AttributionRecorder::record()`, который использует
 * `forceFill` на новой модели. Массовое присвоение открыло бы дорогу
 * «обновить источник» из любого места, а исходная атрибуция — то, за что
 * куратору платят, и переписывать её он не должен.
 */
class AdAttribution extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Только последнее касание. Первое и `user_id` присваиваются явно при
     * создании строки — см. AttributionRecorder.
     */
    protected $fillable = [
        'last_yclid',
        'last_utm_source',
        'last_utm_medium',
        'last_utm_campaign',
        'last_utm_content',
        'last_utm_term',
        'last_campaign_id',
        'last_ad_group_id',
        'last_ad_id',
        'last_keyword_id',
        'last_landing_url',
        'last_referrer',
        'last_touched_at',
    ];

    /** Поля одного касания без префикса — общий словарь для записи и отчётов. */
    public const TOUCH_FIELDS = [
        'yclid',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'campaign_id',
        'ad_group_id',
        'ad_id',
        'keyword_id',
        'landing_url',
        'referrer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class, 'attribution_id');
    }

    /**
     * Снимок касания для административного отчёта.
     *
     * Отдаётся ТОЛЬКО в административной выдаче: партнёр и клиент рекламных
     * идентификаторов не видят — см. PromoCodePresenter.
     *
     * Имя не `touch()`: так называется метод Eloquent, обновляющий отметки
     * времени, и перекрытие с другой сигнатурой роняет загрузку класса.
     *
     * @return array<string, mixed>
     */
    public function touchSnapshot(string $prefix): array
    {
        $touch = [];

        foreach (self::TOUCH_FIELDS as $field) {
            $touch[$field] = $this->{$prefix.'_'.$field};
        }

        $touch['touched_at'] = $this->{$prefix.'_touched_at'}?->toIso8601String();

        return $touch;
    }

    protected function casts(): array
    {
        return [
            'first_touched_at' => 'datetime',
            'last_touched_at' => 'datetime',
        ];
    }
}
