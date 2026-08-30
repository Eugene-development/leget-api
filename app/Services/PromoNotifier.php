<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\PromoCodeDeal;
use App\Models\User;
use App\Models\UserNotification;

/**
 * Внутренние уведомления по промокодам.
 *
 * Единственный канал, который у платформы действительно есть: строка в БД,
 * которую кабинет показывает пользователю. Почта и мессенджеры подключаются
 * вторым слушателем доменного события — здесь их нет и имитации отправки тоже.
 *
 * Тексты уведомлений не содержат ни UTM, ни yclid, ни внутренних
 * идентификаторов клиента: уведомление уходит в том числе партнёру.
 */
final class PromoNotifier
{
    public const DEAL_REPORTED = 'promo.deal_reported';

    public const DEAL_DISPUTED = 'promo.deal_disputed';

    public const DEAL_CONFIRMED = 'promo.deal_confirmed';

    public const DEAL_CLOSED = 'promo.deal_closed';

    public const DEAL_REFUNDED = 'promo.deal_refunded';

    public function dealReported(PromoCodeDeal $deal): void
    {
        $promo = $deal->promoCode;

        // Подтверждает сделку клиент — уведомление адресовано ему, и только ему:
        // куратор и партнёр видят состояние в своём списке без напоминания.
        $this->notify(
            $promo->client_id,
            self::DEAL_REPORTED,
            'Подтвердите сделку по промокоду',
            sprintf(
                'По промокоду %s заявлена сделка на сумму %s %s. Проверьте данные и подтвердите или оспорьте их в личном кабинете.',
                $promo->code,
                $deal->net_amount,
                $deal->currency,
            ),
            $deal,
        );
    }

    public function dealDisputed(PromoCodeDeal $deal): void
    {
        $promo = $deal->promoCode;
        $reason = $deal->client_response?->label() ?? 'без указания причины';

        if ($promo->curator_id !== null) {
            $this->notify(
                $promo->curator_id,
                self::DEAL_DISPUTED,
                'Клиент оспорил сделку',
                sprintf('Промокод %s: клиент ответил «%s». Требуется разбор.', $promo->code, $reason),
                $deal,
            );
        }

        // Администраторы разбирают споры — уведомляем всех: «дежурного»
        // в схеме нет, и выбирать одного было бы выдумкой.
        foreach ($this->superadmins() as $adminId) {
            $this->notify(
                $adminId,
                self::DEAL_DISPUTED,
                'Спор по промокоду',
                sprintf('Промокод %s: клиент ответил «%s».', $promo->code, $reason),
                $deal,
            );
        }
    }

    public function dealConfirmed(PromoCodeDeal $deal, bool $byAdministrator): void
    {
        $promo = $deal->promoCode;
        $body = $byAdministrator
            ? sprintf('Промокод %s: сделка подтверждена администратором платформы.', $promo->code)
            : sprintf('Промокод %s: клиент подтвердил сделку.', $promo->code);

        foreach ([$promo->curator_id, $promo->partner_id] as $recipient) {
            if ($recipient !== null) {
                $this->notify($recipient, self::DEAL_CONFIRMED, 'Сделка подтверждена', $body, $deal);
            }
        }
    }

    public function dealClosed(PromoCodeDeal $deal): void
    {
        $promo = $deal->promoCode;

        foreach (array_filter([$promo->curator_id, $promo->partner_id, $promo->client_id]) as $recipient) {
            $this->notify(
                $recipient,
                self::DEAL_CLOSED,
                'Сделка закрыта',
                sprintf('Промокод %s: сделка принята платформой.', $promo->code),
                $deal,
            );
        }
    }

    public function dealRefunded(PromoCodeDeal $deal): void
    {
        $promo = $deal->promoCode;

        foreach (array_filter([$promo->curator_id, $promo->partner_id]) as $recipient) {
            $this->notify(
                $recipient,
                self::DEAL_REFUNDED,
                'Возврат по сделке',
                sprintf('Промокод %s: оформлен возврат, сделка исключена из расчёта вознаграждения.', $promo->code),
                $deal,
            );
        }
    }

    private function notify(int $userId, string $type, string $title, string $body, PromoCodeDeal $deal): void
    {
        $notification = new UserNotification;

        $notification->forceFill([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            // Только идентификаторы для перехода. Персональные данные страница
            // возьмёт из объекта, проверив права получателя.
            'payload' => [
                'promo_code_id' => $deal->promo_code_id,
                'deal_id' => $deal->getKey(),
            ],
        ])->save();
    }

    /** @return list<int> */
    private function superadmins(): array
    {
        return User::query()
            ->where('role', Role::Superadmin->value)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
