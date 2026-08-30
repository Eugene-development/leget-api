<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PromoDealClosed;
use App\Events\PromoDealRefunded;
use App\Services\CuratorCommissionService;

/**
 * Начисление и сторнирование вознаграждения куратора.
 *
 * Подписан на закрытие сделки, а не на действие куратора: между «куратор внёс
 * сведения» и «платформа закрыла сделку» стоит подтверждение другой стороны,
 * и начисление обязано ждать именно его.
 *
 * Возврат не удаляет начисление — создаёт сторно.
 */
final class AccrueCuratorCommission
{
    public function __construct(private readonly CuratorCommissionService $commissions) {}

    public function closed(PromoDealClosed $event): void
    {
        $this->commissions->accrue($event->deal);
    }

    public function refunded(PromoDealRefunded $event): void
    {
        $this->commissions->reverse($event->deal, $event->reason);
    }
}
