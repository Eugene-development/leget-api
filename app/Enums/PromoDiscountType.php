<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Вид скидки промокода.
 *
 * Валюта принадлежит только фиксированной скидке: «15 % в рублях» — бессмыслица,
 * и требовать валюту у процентной означало бы хранить поле, которое ничего
 * не значит. Правило проверяется в PromoCodeService при создании.
 */
enum PromoDiscountType: string
{
    case Percent = 'percent';

    case Fixed = 'fixed';

    public function requiresCurrency(): bool
    {
        return $this === self::Fixed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Процент',
            self::Fixed => 'Фиксированная сумма',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
