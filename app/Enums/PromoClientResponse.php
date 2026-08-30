<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ответ клиента на заявленную сделку.
 *
 * Ровно один из ответов подтверждает сделку, остальные четыре — оспаривают.
 * Разделение выражено методом `isConfirmation()`, а не списком «плохих»
 * значений в сервисе: добавится пятая причина спора — код разбора не тронется.
 *
 * Причина спора сохраняется отдельно от статуса: админу, который разбирает
 * спор, «сделки не было» и «скидка не предоставлена» требуют разных действий.
 */
enum PromoClientResponse: string
{
    case Confirmed = 'confirmed';

    case AmountWrong = 'amount_wrong';

    case DiscountMissing = 'discount_missing';

    case NoDeal = 'no_deal';

    case OrderCancelled = 'order_cancelled';

    public function isConfirmation(): bool
    {
        return $this === self::Confirmed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Подтверждаю',
            self::AmountWrong => 'Сумма неверна',
            self::DiscountMissing => 'Скидка не предоставлена',
            self::NoDeal => 'Сделки не было',
            self::OrderCancelled => 'Заказ отменён',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
