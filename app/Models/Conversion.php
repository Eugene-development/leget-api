<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Conversion extends Model
{
    use HasUlids;

    public const CHANNEL_ONLINE = 'online';

    public const CHANNEL_OFFLINE = 'offline';

    /** Тип офлайн-конверсии, порождённой закрытой сделкой по промокоду. */
    public const TYPE_PROMO_DEAL = 'offline_promo_deal';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'channel',
        'type',
        'name',
        'contact',
        'ad_id',
        'comment',
        'source_url',
        'service_request_id',
        // Закрытая сделка по промокоду становится офлайн-конверсией — реестр
        // конверсий и есть точка передачи в Яндекс. Уникальный индекс на этой
        // колонке делает создание идемпотентным: повторное закрытие или
        // переигранное событие второй строки не создаст.
        'promo_code_deal_id',
    ];
}
