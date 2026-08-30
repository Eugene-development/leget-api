<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PromoCodeStatus;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доменная ошибка промокодов.
 *
 * Отдельный тип, а не `abort(422)` внутри сервиса: сервис не знает про HTTP,
 * а контроллер не должен разбирать текст сообщения, чтобы понять, что
 * произошло. Код ошибки (`code`) уходит клиенту и годится для разветвления
 * интерфейса; статус — для ответа.
 */
final class PromoCodeException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'PROMO_ERROR',
        private readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function transition(PromoCodeStatus $from, PromoCodeStatus $to): self
    {
        return new self(
            sprintf('Переход «%s» → «%s» не разрешён.', $from->label(), $to->label()),
            'PROMO_TRANSITION_FORBIDDEN',
        );
    }

    public static function expired(): self
    {
        return new self('Срок действия промокода истёк.', 'PROMO_EXPIRED');
    }

    public static function notStarted(): self
    {
        return new self('Промокод ещё не вступил в силу.', 'PROMO_NOT_STARTED');
    }

    public static function notRedeemable(PromoCodeStatus $status): self
    {
        return new self(
            sprintf('Промокод в состоянии «%s» применить нельзя.', $status->label()),
            'PROMO_NOT_REDEEMABLE',
        );
    }

    public static function alreadyRedeemed(): self
    {
        return new self('По этому промокоду сделка уже заявлена.', 'PROMO_ALREADY_REDEEMED', Response::HTTP_CONFLICT);
    }

    public static function partnerRequired(): self
    {
        return new self('Партнёр не назначен — заявить сделку не от кого.', 'PROMO_PARTNER_REQUIRED');
    }

    public static function reasonRequired(): self
    {
        return new self('Требуется основание.', 'PROMO_REASON_REQUIRED');
    }

    public static function forbidden(string $message = 'Недостаточно прав для этого действия.'): self
    {
        return new self($message, 'PROMO_FORBIDDEN', Response::HTTP_FORBIDDEN);
    }

    public static function locked(string $message): self
    {
        return new self($message, 'PROMO_LOCKED', Response::HTTP_CONFLICT);
    }
}
