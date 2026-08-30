<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PromoCodeDeal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Партнёр или куратор заявил о сделке.
 *
 * Событие, а не прямой вызов уведомления: подключение почты, SMS или
 * мессенджера должно быть вторым слушателем, а не правкой сервиса. Сейчас
 * слушатель один — внутреннее уведомление в кабинете.
 */
final class PromoDealReported
{
    use Dispatchable;

    public function __construct(public readonly PromoCodeDeal $deal) {}
}
