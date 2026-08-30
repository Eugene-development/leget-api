<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PromoDealClosed;
use App\Events\PromoDealConfirmed;
use App\Events\PromoDealDisputed;
use App\Events\PromoDealRefunded;
use App\Events\PromoDealReported;
use App\Services\PromoNotifier;

/**
 * Уведомления кабинета по доменным событиям промокодов.
 *
 * Слушатель, а не вызов внутри сервиса: почта, SMS и мессенджеры подключатся
 * вторым слушателем тех же событий, не трогая бизнес-логику.
 *
 * Синхронный: уведомление обязано появиться в той же транзакции, что и переход
 * статуса. Очередь дала бы окно, в котором клиенту нечего подтверждать, а код
 * уже ждёт его ответа.
 */
final class SendPromoNotifications
{
    public function __construct(private readonly PromoNotifier $notifier) {}

    public function reported(PromoDealReported $event): void
    {
        $this->notifier->dealReported($event->deal);
    }

    public function disputed(PromoDealDisputed $event): void
    {
        $this->notifier->dealDisputed($event->deal);
    }

    public function confirmed(PromoDealConfirmed $event): void
    {
        $this->notifier->dealConfirmed($event->deal, $event->byAdministrator);
    }

    public function closed(PromoDealClosed $event): void
    {
        $this->notifier->dealClosed($event->deal);
    }

    public function refunded(PromoDealRefunded $event): void
    {
        $this->notifier->dealRefunded($event->deal);
    }
}
