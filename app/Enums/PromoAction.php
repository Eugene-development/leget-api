<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Действия, попадающие в журнал аудита.
 *
 * Список закрытый: имя действия — часть контракта журнала, и свободная строка
 * рано или поздно даст `deal_reported` в одном месте и `report_deal` в другом,
 * после чего выборка «все внесения сделки» перестанет находить половину.
 *
 * `SensitiveViewed` пишется только там, где раскрывается рекламная аналитика
 * (UTM, yclid) — то есть в административных отчётах. Просмотр собственного
 * промокода клиентом в журнал не пишется: это обычная работа кабинета, и запись
 * на каждый показ страницы превратила бы журнал в лог доступа.
 */
enum PromoAction: string
{
    case Created = 'promo.created';

    case Activated = 'promo.activated';

    case PartnerAssigned = 'promo.partner_assigned';

    case TermsChanged = 'promo.terms_changed';

    case Presented = 'promo.presented';

    case OrderCreated = 'promo.order_created';

    case DealReported = 'promo.deal_reported';

    case DealReportedOnBehalf = 'promo.deal_reported_on_behalf';

    case ClientConfirmed = 'promo.client_confirmed';

    case AdminConfirmed = 'promo.admin_confirmed';

    case Disputed = 'promo.disputed';

    case Closed = 'promo.closed';

    case Cancelled = 'promo.cancelled';

    case Refunded = 'promo.refunded';

    case Expired = 'promo.expired';

    case AmountChanged = 'promo.amount_changed';

    case CommissionAccrued = 'promo.commission_accrued';

    case CommissionReversed = 'promo.commission_reversed';

    case SensitiveViewed = 'promo.sensitive_viewed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Промокод создан',
            self::Activated => 'Промокод активирован',
            self::PartnerAssigned => 'Назначен партнёр',
            self::TermsChanged => 'Изменены условия',
            self::Presented => 'Код предъявлен',
            self::OrderCreated => 'Оформлен заказ',
            self::DealReported => 'Заявлена сделка',
            self::DealReportedOnBehalf => 'Сделка заявлена куратором от имени партнёра',
            self::ClientConfirmed => 'Клиент подтвердил сделку',
            self::AdminConfirmed => 'Административное подтверждение',
            self::Disputed => 'Сделка оспорена',
            self::Closed => 'Сделка закрыта',
            self::Cancelled => 'Отменено',
            self::Refunded => 'Возврат',
            self::Expired => 'Срок действия истёк',
            self::AmountChanged => 'Изменена сумма',
            self::CommissionAccrued => 'Начислено вознаграждение',
            self::CommissionReversed => 'Начисление сторнировано',
            self::SensitiveViewed => 'Просмотр рекламной аналитики',
        };
    }
}
