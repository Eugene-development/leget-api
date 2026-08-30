<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Тип записи в начислениях куратора.
 *
 * Возврат после закрытия сделки не удаляет начисление, а добавляет
 * сторнирующую строку — история остаётся, а сумма к выплате считается как
 * сумма всех записей. Уникальный индекс `(promo_code_deal_id, entry_type)`
 * не даёт ни начислить дважды, ни сторнировать дважды.
 */
enum CommissionEntryType: string
{
    case Accrual = 'accrual';

    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Accrual => 'Начисление',
            self::Reversal => 'Сторно',
        };
    }
}
