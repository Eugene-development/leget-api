<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PromoCode;
use App\Models\PromoCodeDeal;
use App\Models\PromoCodeEvent;

/**
 * Что именно каждая роль видит в промокоде.
 *
 * Отдельный класс, а не `$hidden` в модели: набор скрытого зависит не от поля,
 * а от того, кто смотрит. Одна и та же строка `promo_codes` показывается
 * клиенту, партнёру, куратору и админу по-разному, и держать это в четырёх
 * контроллерах значит однажды забыть про UTM в пятом.
 *
 * Жёсткое правило: **yclid и UTM не покидают административную выдачу**.
 * Ни клиент, ни партнёр, ни даже куратор рекламных идентификаторов не получают —
 * ни в списке, ни в карточке, ни в уведомлении.
 *
 * Партнёр вдобавок не видит внутренний идентификатор клиента и его email:
 * ему нужны имя и телефон, чтобы связаться, и ничего сверх этого.
 */
final class PromoCodePresenter
{
    /**
     * Общая часть — то, что видно всем ролям без исключения.
     *
     * @return array<string, mixed>
     */
    public function base(PromoCode $promo): array
    {
        return [
            'id' => $promo->getKey(),
            'code' => $promo->code,
            'status' => $promo->status->value,
            'status_label' => $promo->status->label(),
            'discount_type' => $promo->discount_type->value,
            'discount_value' => $promo->discount_value,
            'currency' => $promo->currency,
            'minimum_order_amount' => $promo->minimum_order_amount,
            'subject_type' => $promo->subject_type->value,
            'subject_title' => $promo->subject_title,
            'terms' => $promo->terms,
            'starts_at' => $promo->starts_at?->toIso8601String(),
            'expires_at' => $promo->expires_at?->toIso8601String(),
            'created_at' => $promo->created_at?->toIso8601String(),
            'activated_at' => $promo->activated_at?->toIso8601String(),
            'presented_at' => $promo->presented_at?->toIso8601String(),
            'deal_reported_at' => $promo->deal_reported_at?->toIso8601String(),
            'client_confirmed_at' => $promo->client_confirmed_at?->toIso8601String(),
            'closed_at' => $promo->closed_at?->toIso8601String(),
        ];
    }

    /**
     * Карточка для клиента: партнёр, размер скидки, условия, срок, сделка.
     *
     * Рекламной аналитики нет вовсе — клиент не должен видеть, из какой
     * кампании его привели.
     *
     * @return array<string, mixed>
     */
    public function forClient(PromoCode $promo): array
    {
        return $this->base($promo) + [
            'partner' => $promo->partner === null ? null : [
                'name' => $promo->partner->name,
                'company' => $promo->partner->partnerProfile?->company,
                'phone' => $promo->partner->phone,
            ],
            'curator' => $promo->curator === null ? null : [
                'name' => $promo->curator->name,
            ],
            'deal' => $this->deal($promo->deal),
        ];
    }

    /**
     * Карточка для партнёра.
     *
     * Клиент показан в пределах разрешённого: имя и телефон — чтобы связаться.
     * Ни email, ни внутренний `id`, ни тем более UTM или yclid сюда не попадают.
     *
     * @return array<string, mixed>
     */
    public function forPartner(PromoCode $promo): array
    {
        return $this->base($promo) + [
            'client' => $promo->client === null ? null : [
                'name' => $promo->client->name,
                'phone' => $promo->client->phone,
            ],
            'curator' => $promo->curator === null ? null : [
                'name' => $promo->curator->name,
                'phone' => $promo->curator->phone,
            ],
            'deal' => $this->deal($promo->deal),
        ];
    }

    /**
     * Карточка для куратора.
     *
     * Куратор ведёт клиента и видит его контакты, но рекламная аналитика ему
     * тоже не показывается: менять атрибуцию он не может, а знать конкретный
     * yclid для работы не нужно.
     *
     * @return array<string, mixed>
     */
    public function forCurator(PromoCode $promo): array
    {
        return $this->base($promo) + [
            'client' => $promo->client === null ? null : [
                'id' => $promo->client->id,
                'name' => $promo->client->name,
                'email' => $promo->client->email,
                'phone' => $promo->client->phone,
            ],
            'partner' => $promo->partner === null ? null : [
                'id' => $promo->partner->id,
                'name' => $promo->partner->name,
                'company' => $promo->partner->partnerProfile?->company,
                'phone' => $promo->partner->phone,
            ],
            'has_attribution' => $promo->attribution_id !== null,
            'deal' => $this->deal($promo->deal),
        ];
    }

    /**
     * Полная карточка администратора — единственная выдача с рекламными данными.
     *
     * @return array<string, mixed>
     */
    public function forAdmin(PromoCode $promo): array
    {
        $attribution = $promo->attribution;

        return $this->forCurator($promo) + [
            'created_by' => $promo->created_by,
            'activated_by' => $promo->activated_by,
            'deal_reported_by' => $promo->deal_reported_by,
            'confirmed_by' => $promo->confirmed_by,
            'closed_by' => $promo->closed_by,
            'disputed_at' => $promo->disputed_at?->toIso8601String(),
            'cancelled_at' => $promo->cancelled_at?->toIso8601String(),
            'refunded_at' => $promo->refunded_at?->toIso8601String(),
            'expired_at' => $promo->expired_at?->toIso8601String(),
            'attribution' => $attribution === null ? null : [
                'id' => $attribution->getKey(),
                'visitor_id' => $attribution->visitor_id,
                'first' => $attribution->touchSnapshot('first'),
                'last' => $attribution->touchSnapshot('last'),
            ],
            'timings' => $this->timings($promo),
        ];
    }

    /**
     * Сведения о сделке.
     *
     * Общие для всех ролей: номер заказа, суммы и дата — это ровно то, что
     * клиент должен проверить, подтверждая. Кто внёс и от чьего имени —
     * тоже: скрывать от клиента, что данные внёс куратор, а не партнёр,
     * значило бы просить его подтвердить неизвестно чьи слова.
     *
     * @return array<string, mixed>|null
     */
    public function deal(?PromoCodeDeal $deal): ?array
    {
        if (! $deal instanceof PromoCodeDeal) {
            return null;
        }

        return [
            'id' => $deal->getKey(),
            'order_number' => $deal->order_number,
            'deal_date' => $deal->deal_date?->toDateString(),
            'gross_amount' => $deal->gross_amount,
            'discount_amount' => $deal->discount_amount,
            'net_amount' => $deal->net_amount,
            'currency' => $deal->currency,
            'category' => $deal->category,
            'comment' => $deal->comment,
            'document_url' => $deal->document_url,
            'reported_by_role' => $deal->reported_by_role,
            'on_behalf_of_partner' => $deal->on_behalf_of_partner,
            'behalf_reason' => $deal->behalf_reason,
            'client_response' => $deal->client_response?->value,
            'client_response_label' => $deal->client_response?->label(),
            'client_responded_at' => $deal->client_responded_at?->toIso8601String(),
            'client_comment' => $deal->client_comment,
            'confirmation_reason' => $deal->confirmation_reason,
            'closed_at' => $deal->closed_at?->toIso8601String(),
        ];
    }

    /**
     * Запись журнала для показа в интерфейсе.
     *
     * @return array<string, mixed>
     */
    public function event(PromoCodeEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'action' => $event->action->value,
            'action_label' => $event->action->label(),
            'actor_id' => $event->actor_id,
            'actor_role' => $event->actor_role,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'changes' => $event->changes,
            'reason' => $event->reason,
            'ip_address' => $event->ip_address,
            'user_agent' => $event->user_agent,
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    /**
     * История действий для партнёра и куратора — без IP и user agent.
     *
     * Журнал доступа к системе — данные администратора: партнёру достаточно
     * знать, что произошло и когда.
     *
     * @return array<string, mixed>
     */
    public function eventForParticipant(PromoCodeEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'action' => $event->action->value,
            'action_label' => $event->action->label(),
            'actor_role' => $event->actor_role,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'reason' => $event->reason,
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    /**
     * Интервалы воронки — то, ради чего атрибуция связывается со сделкой.
     *
     * Считаются в секундах: единица времени должна быть одна, а перевод
     * в часы или дни — работа интерфейса, а не отчёта.
     *
     * @return array<string, int|null>
     */
    private function timings(PromoCode $promo): array
    {
        $firstTouch = $promo->attribution?->first_touched_at;
        $registered = $promo->client?->created_at;

        return [
            'ad_to_registration' => $this->seconds($firstTouch, $registered),
            'registration_to_activation' => $this->seconds($registered, $promo->activated_at),
            'activation_to_deal' => $this->seconds($promo->activated_at, $promo->deal_reported_at),
            'deal_to_close' => $this->seconds($promo->deal_reported_at, $promo->closed_at),
        ];
    }

    private function seconds(mixed $from, mixed $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        $seconds = $from->diffInSeconds($to, false);

        return $seconds < 0 ? null : (int) $seconds;
    }
}
