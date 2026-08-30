<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Жизненный цикл промокода.
 *
 * Переходы объявлены здесь, а не разбросаны по сервису: «какие состояния
 * возможны после этого» — свойство самого состояния, и таблица переходов рядом
 * с перечислением читается целиком, а не собирается по коду из шести мест.
 *
 * Ключевое разделение проходит между `deal_reported`, `client_confirmed`
 * и `closed`. Куратор получает вознаграждение за закрытые сделки и при этом
 * может вносить сведения от имени партнёра, поэтому его действие обрывается
 * на `deal_reported`: дальше ходит другая сторона. Из `deal_reported` попасть
 * в `closed` напрямую нельзя вообще ни при какой роли — только через
 * `client_confirmed`, куда переводит либо клиент, либо админ с основанием.
 *
 * Терминальные состояния (`closed`, `cancelled`, `refunded`, `expired`)
 * переходов не имеют, кроме `closed → refunded`: возврат оформляется уже после
 * закрытия и должен породить корректировку начисления, а не переписать историю.
 */
enum PromoCodeStatus: string
{
    case Created = 'created';

    case Activated = 'activated';

    case Presented = 'presented';

    case OrderCreated = 'order_created';

    case DealReported = 'deal_reported';

    case ClientConfirmed = 'client_confirmed';

    case Closed = 'closed';

    case Disputed = 'disputed';

    case Cancelled = 'cancelled';

    case Refunded = 'refunded';

    case Expired = 'expired';

    /**
     * Куда состояние может перейти.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Партнёру ещё не виден: код создан, но не активирован куратором.
            self::Created => [self::Activated, self::Cancelled, self::Expired],

            // Активирован — виден клиенту, куратору и назначенному партнёру.
            // `deal_reported` достижим и отсюда: отметка о предъявлении —
            // удобство партнёра, а не обязательная ступень, и требовать её
            // значило бы отвергать сделку, которая состоялась.
            self::Activated => [self::Presented, self::OrderCreated, self::DealReported, self::Cancelled, self::Expired],

            // Предъявлен партнёру.
            self::Presented => [self::OrderCreated, self::DealReported, self::Cancelled, self::Expired],

            // Оформлен заказ или договор.
            self::OrderCreated => [self::DealReported, self::Cancelled, self::Expired],

            // Сведения внесены партнёром или куратором. Дальше ходит клиент
            // (подтвердит или оспорит) либо админ с основанием.
            self::DealReported => [self::ClientConfirmed, self::Disputed, self::Cancelled],

            // Клиент (или админ с основанием) подтвердил сведения. Только
            // отсюда платформа закрывает сделку и начисляет вознаграждение.
            self::ClientConfirmed => [self::Closed, self::Disputed, self::Cancelled],

            // Спор разбирает админ: либо признаёт сведения, либо отменяет.
            self::Disputed => [self::ClientConfirmed, self::Cancelled],

            // Закрыта. Возврат допустим и после закрытия — он создаёт
            // корректировку начисления, а не удаляет его.
            self::Closed => [self::Refunded],

            self::Cancelled, self::Refunded, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Код ещё живой: с ним можно работать дальше. */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Можно ли предъявить и погасить код.
     *
     * Истёкший, отменённый и возвращённый — нельзя. Уже закрытый — тоже:
     * повторное погашение не должно создавать вторую сделку.
     */
    public function isRedeemable(): bool
    {
        return in_array($this, [
            self::Activated,
            self::Presented,
            self::OrderCreated,
        ], true);
    }

    /** Виден ли код назначенному партнёру. До активации — нет. */
    public function isVisibleToPartner(): bool
    {
        return $this !== self::Created;
    }

    /** Основание для вознаграждения куратора даёт только это состояние. */
    public function countsTowardsCommission(): bool
    {
        return $this === self::Closed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Создан',
            self::Activated => 'Активирован',
            self::Presented => 'Предъявлен',
            self::OrderCreated => 'Оформлен заказ',
            self::DealReported => 'Сделка заявлена',
            self::ClientConfirmed => 'Подтверждена клиентом',
            self::Closed => 'Закрыта',
            self::Disputed => 'Оспорена',
            self::Cancelled => 'Отменён',
            self::Refunded => 'Возврат',
            self::Expired => 'Истёк',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
