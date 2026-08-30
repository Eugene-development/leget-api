<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PromoCodeDeal;
use Illuminate\Foundation\Events\Dispatchable;

/** Клиент (или админ с основанием) подтвердил сведения о сделке. */
final class PromoDealConfirmed
{
    use Dispatchable;

    public function __construct(
        public readonly PromoCodeDeal $deal,
        public readonly bool $byAdministrator = false,
    ) {}
}
