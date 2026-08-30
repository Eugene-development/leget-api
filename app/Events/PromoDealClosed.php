<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PromoCodeDeal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Платформа приняла сделку в аналитику.
 *
 * Точка, из которой растут две ветки: начисление вознаграждения куратору
 * и офлайн-конверсия для Яндекса. Обе — слушатели, чтобы закрытие сделки
 * не знало ни про формулу вознаграждения, ни про рекламный кабинет.
 */
final class PromoDealClosed
{
    use Dispatchable;

    public function __construct(public readonly PromoCodeDeal $deal) {}
}
