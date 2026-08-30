<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PromoCodeDeal;
use Illuminate\Foundation\Events\Dispatchable;

/** Клиент оспорил заявленную сделку — уведомляются куратор и администраторы. */
final class PromoDealDisputed
{
    use Dispatchable;

    public function __construct(public readonly PromoCodeDeal $deal) {}
}
