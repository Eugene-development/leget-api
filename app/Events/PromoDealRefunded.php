<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PromoCodeDeal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * По закрытой сделке оформлен возврат.
 *
 * Начисление куратору не удаляется — слушатель создаёт сторнирующую запись,
 * чтобы история осталась, а сумма к выплате уменьшилась.
 */
final class PromoDealRefunded
{
    use Dispatchable;

    public function __construct(
        public readonly PromoCodeDeal $deal,
        public readonly ?string $reason = null,
    ) {}
}
