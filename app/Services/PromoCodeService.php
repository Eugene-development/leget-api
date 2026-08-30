<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PromoAction;
use App\Enums\PromoClientResponse;
use App\Enums\PromoCodeStatus;
use App\Enums\PromoDiscountType;
use App\Enums\PromoSubjectType;
use App\Enums\Role;
use App\Events\PromoDealClosed;
use App\Events\PromoDealConfirmed;
use App\Events\PromoDealDisputed;
use App\Events\PromoDealRefunded;
use App\Events\PromoDealReported;
use App\Exceptions\PromoCodeException;
use App\Models\AdAttribution;
use App\Models\PromoCode;
use App\Models\PromoCodeDeal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Жизненный цикл промокода: единственное место, где меняется его состояние.
 *
 * Контроллеры сюда только передают уже проверенные данные и аутентифицированного
 * актора. Ни один атрибут не присваивается массово: `client_id`, `curator_id`,
 * `partner_id` и `status` ставит этот сервис, а не тело запроса — иначе
 * `{"status":"closed"}` в JSON означало бы закрытую сделку без единой проверки.
 *
 * Три ключевых свойства, ради которых сервис существует:
 *
 * 1. **Переходы контролируются.** Разрешённые переходы объявлены в
 *    `PromoCodeStatus::allowedTransitions()`; `created → closed` невозможен
 *    в принципе, а не «не предусмотрен интерфейсом».
 * 2. **Действие куратора не равно подтверждённой сделке.** Куратор доводит код
 *    до `deal_reported` и останавливается. Дальше ходит клиент (подтвердит или
 *    оспорит) либо администратор — с обязательным основанием и записью в журнал.
 * 3. **Двойное погашение исключено.** Одна сделка на код держится уникальным
 *    индексом БД, а не проверкой «а есть ли уже»: проверка проигрывает гонке
 *    двух одновременных запросов, индекс — нет.
 *
 * Деньги считаются bcmath со скалой 2, как в BillingService. Float в денежных
 * расчётах не участвует нигде: `net_amount` пересчитывается на сервере из
 * `gross_amount` и `discount_amount`, присланному фронтендом значению не верим.
 */
final class PromoCodeService
{
    private const SCALE = 2;

    public function __construct(
        private readonly PromoCodeGenerator $generator,
        private readonly PromoAuditLogger $audit,
    ) {}

    /**
     * Создать промокод.
     *
     * Клиент, куратор и партнёр приходят объектами, а не идентификаторами из
     * запроса: разрешать их обязан контроллер, и подмена `client_id` в теле
     * до сервиса не доходит.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, User $client, array $data): PromoCode
    {
        $discountType = $data['discount_type'] instanceof PromoDiscountType
            ? $data['discount_type']
            : PromoDiscountType::from((string) $data['discount_type']);

        $subjectType = $data['subject_type'] instanceof PromoSubjectType
            ? $data['subject_type']
            : PromoSubjectType::from((string) $data['subject_type']);

        $discountValue = $this->money($data['discount_value'] ?? null, 'Размер скидки');
        $this->assertDiscount($discountType, $discountValue);

        $minimum = isset($data['minimum_order_amount']) && $data['minimum_order_amount'] !== null
            ? $this->money($data['minimum_order_amount'], 'Минимальная сумма заказа')
            : null;

        if ($minimum !== null && bccomp($minimum, '0', self::SCALE) <= 0) {
            throw new PromoCodeException('Минимальная сумма заказа должна быть больше нуля.', 'PROMO_MIN_ORDER_INVALID');
        }

        $startsAt = $this->moment($data['starts_at'] ?? null) ?? CarbonImmutable::now();
        $expiresAt = $this->moment($data['expires_at'] ?? null)
            ?? $startsAt->addDays(max(1, (int) config('promo.defaults.valid_days')));

        if ($expiresAt->lessThanOrEqualTo($startsAt)) {
            throw new PromoCodeException('Дата окончания должна быть позже даты начала.', 'PROMO_DATES_INVALID');
        }

        $subjectId = $data['subject_id'] ?? null;
        $this->assertSubjectExists($subjectType, is_string($subjectId) ? $subjectId : null);

        // Атрибуция берётся из БД по клиенту, а не из запроса: куратор не должен
        // иметь возможности назначить сделке «правильный» рекламный источник.
        $attribution = AdAttribution::query()->where('user_id', $client->id)->first();

        $partner = $data['partner'] ?? null;
        $curator = $data['curator'] ?? null;

        return DB::transaction(function () use (
            $actor, $client, $curator, $partner, $attribution, $subjectType, $subjectId,
            $discountType, $discountValue, $minimum, $startsAt, $expiresAt, $data
        ): PromoCode {
            $promo = $this->insertWithUniqueCode([
                'client_id' => $client->id,
                'curator_id' => $curator instanceof User ? $curator->id : null,
                'partner_id' => $partner instanceof User ? $partner->id : null,
                'attribution_id' => $attribution?->getKey(),
                'subject_type' => $subjectType->value,
                'subject_id' => $subjectId,
                'subject_title' => (string) $data['subject_title'],
                'discount_type' => $discountType->value,
                'discount_value' => $discountValue,
                'currency' => $discountType->requiresCurrency()
                    ? (string) ($data['currency'] ?? config('promo.defaults.currency'))
                    : null,
                'minimum_order_amount' => $minimum,
                'terms' => $data['terms'] ?? null,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'status' => PromoCodeStatus::Created->value,
                'created_by' => $actor->id,
            ]);

            $this->audit->log(
                action: PromoAction::Created,
                subjectType: 'promo_code',
                subjectId: (string) $promo->getKey(),
                actor: $actor,
                promoCodeId: (string) $promo->getKey(),
                to: PromoCodeStatus::Created,
                changes: [
                    'discount_type' => $discountType->value,
                    'discount_value' => $discountValue,
                    'subject_type' => $subjectType->value,
                    'partner_id' => $promo->partner_id,
                    'curator_id' => $promo->curator_id,
                    'attribution_linked' => $attribution !== null,
                ],
            );

            return $promo;
        });
    }

    /** Активация куратором: код становится виден клиенту и назначенному партнёру. */
    public function activate(User $actor, PromoCode $promo): PromoCode
    {
        $this->expireIfPastDue($promo, $actor);

        return DB::transaction(function () use ($actor, $promo): PromoCode {
            $promo = $this->lock($promo);

            // Идемпотентность: повторное нажатие не должно быть ошибкой.
            if ($promo->status === PromoCodeStatus::Activated) {
                return $promo;
            }

            $this->assertNotExpired($promo, $actor);

            return $this->transition($actor, $promo, PromoCodeStatus::Activated, PromoAction::Activated, [
                'activated_at' => now(),
                'activated_by' => $actor->id,
            ]);
        });
    }

    /**
     * Назначить партнёра.
     *
     * После активации переназначение запрещено: код уже показан клиенту
     * с именем партнёра, и незаметная подмена организации превратила бы
     * обещанную скидку в чужую.
     */
    public function assignPartner(User $actor, PromoCode $promo, User $partner): PromoCode
    {
        if (($partner->role ?? Role::Client) !== Role::Partner) {
            throw new PromoCodeException('Назначить можно только пользователя с ролью «Партнёр».', 'PROMO_PARTNER_INVALID');
        }

        return DB::transaction(function () use ($actor, $promo, $partner): PromoCode {
            $promo = $this->lock($promo);

            if ($promo->partner_id === $partner->id) {
                return $promo;
            }

            if ($promo->status !== PromoCodeStatus::Created && $promo->partner_id !== null) {
                throw PromoCodeException::locked('Партнёр уже назначен активированному промокоду — переназначение запрещено.');
            }

            if ($promo->status->isTerminal()) {
                throw PromoCodeException::notRedeemable($promo->status);
            }

            $before = $promo->partner_id;
            $promo->forceFill(['partner_id' => $partner->id])->save();

            $this->audit->log(
                action: PromoAction::PartnerAssigned,
                subjectType: 'promo_code',
                subjectId: (string) $promo->getKey(),
                actor: $actor,
                promoCodeId: (string) $promo->getKey(),
                changes: ['partner_id' => ['from' => $before, 'to' => $partner->id]],
            );

            return $promo;
        });
    }

    /**
     * Изменить условия.
     *
     * Только до активации. Активированный код — обещание, данное клиенту
     * и партнёру; менять его размер задним числом нельзя ни куратору,
     * ни партнёру.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateTerms(User $actor, PromoCode $promo, array $data): PromoCode
    {
        return DB::transaction(function () use ($actor, $promo, $data): PromoCode {
            $promo = $this->lock($promo);

            if ($promo->status !== PromoCodeStatus::Created) {
                throw PromoCodeException::locked('Условия активированного промокода изменить нельзя.');
            }

            $changes = [];

            if (array_key_exists('discount_value', $data) && $data['discount_value'] !== null) {
                $value = $this->money($data['discount_value'], 'Размер скидки');
                $this->assertDiscount($promo->discount_type, $value);
                $changes['discount_value'] = ['from' => $promo->discount_value, 'to' => $value];
                $promo->discount_value = $value;
            }

            if (array_key_exists('terms', $data)) {
                $changes['terms'] = ['from' => $promo->terms, 'to' => $data['terms']];
                $promo->terms = $data['terms'];
            }

            if (array_key_exists('expires_at', $data) && $data['expires_at'] !== null) {
                $expires = $this->moment($data['expires_at']);
                $changes['expires_at'] = [
                    'from' => $promo->expires_at?->toIso8601String(),
                    'to' => $expires?->toIso8601String(),
                ];
                $promo->expires_at = $expires;
            }

            if ($changes === []) {
                return $promo;
            }

            $promo->save();

            $this->audit->log(
                action: PromoAction::TermsChanged,
                subjectType: 'promo_code',
                subjectId: (string) $promo->getKey(),
                actor: $actor,
                promoCodeId: (string) $promo->getKey(),
                changes: $changes,
            );

            return $promo;
        });
    }

    /** Партнёр отметил, что код предъявлен. */
    public function markPresented(User $actor, PromoCode $promo): PromoCode
    {
        $this->expireIfPastDue($promo, $actor);

        return DB::transaction(function () use ($actor, $promo): PromoCode {
            $promo = $this->lock($promo);

            if (in_array($promo->status, [PromoCodeStatus::Presented, PromoCodeStatus::OrderCreated], true)) {
                return $promo;
            }

            $this->assertUsable($promo, $actor);

            return $this->transition($actor, $promo, PromoCodeStatus::Presented, PromoAction::Presented, [
                'presented_at' => now(),
            ]);
        });
    }

    /** Партнёр оформил заказ или договор. */
    public function markOrderCreated(User $actor, PromoCode $promo): PromoCode
    {
        $this->expireIfPastDue($promo, $actor);

        return DB::transaction(function () use ($actor, $promo): PromoCode {
            $promo = $this->lock($promo);

            if ($promo->status === PromoCodeStatus::OrderCreated) {
                return $promo;
            }

            $this->assertUsable($promo, $actor);

            return $this->transition($actor, $promo, PromoCodeStatus::OrderCreated, PromoAction::OrderCreated, [
                'order_created_at' => now(),
            ]);
        });
    }

    /**
     * Заявить сделку.
     *
     * Куратор обязан объявить, что действует от имени партнёра, и указать
     * основание — иначе «сделку внёс партнёр» и «сделку внёс заинтересованный
     * куратор» выглядели бы в журнале одинаково.
     *
     * Сделка НЕ становится подтверждённой: код уходит в `deal_reported`,
     * а следующий ход — за клиентом.
     *
     * @param  array<string, mixed>  $data
     */
    public function reportDeal(User $actor, PromoCode $promo, array $data): PromoCodeDeal
    {
        $role = $actor->role ?? Role::Client;
        $onBehalf = (bool) ($data['on_behalf_of_partner'] ?? false);
        $behalfReason = trim((string) ($data['behalf_reason'] ?? ''));

        if ($role === Role::Curator) {
            if (! $onBehalf) {
                throw new PromoCodeException(
                    'Куратор вносит сведения только от имени партнёра — отметьте это явно.',
                    'PROMO_BEHALF_REQUIRED',
                );
            }

            if ($behalfReason === '') {
                throw PromoCodeException::reasonRequired();
            }
        }

        $gross = $this->money($data['gross_amount'] ?? null, 'Сумма заказа');
        $discount = $this->money($data['discount_amount'] ?? null, 'Размер скидки');

        if (bccomp($gross, '0', self::SCALE) <= 0) {
            throw new PromoCodeException('Сумма заказа должна быть больше нуля.', 'PROMO_AMOUNT_INVALID');
        }

        if (bccomp($discount, '0', self::SCALE) < 0) {
            throw new PromoCodeException('Размер скидки не может быть отрицательным.', 'PROMO_DISCOUNT_INVALID');
        }

        // Фиксированная скидка не должна превышать сумму заказа, а итог
        // не может уйти в минус. Проверка идёт по обеим сторонам, потому что
        // «скидка больше суммы» и «итог отрицательный» — одно и то же условие,
        // и полагаться на то, что фронтенд посчитал верно, нельзя.
        if (bccomp($discount, $gross, self::SCALE) > 0) {
            throw new PromoCodeException('Скидка не может превышать сумму заказа.', 'PROMO_DISCOUNT_EXCEEDS_ORDER');
        }

        $net = bcsub($gross, $discount, self::SCALE);

        if (bccomp($net, '0', self::SCALE) < 0) {
            throw new PromoCodeException('Итоговая сумма не может быть отрицательной.', 'PROMO_NET_NEGATIVE');
        }

        if ($promo->minimum_order_amount !== null
            && bccomp($gross, (string) $promo->minimum_order_amount, self::SCALE) < 0) {
            throw new PromoCodeException(
                sprintf('Промокод действует от суммы заказа %s.', $promo->minimum_order_amount),
                'PROMO_MIN_ORDER_NOT_MET',
            );
        }

        $this->expireIfPastDue($promo, $actor);

        return DB::transaction(function () use ($actor, $promo, $data, $role, $onBehalf, $behalfReason, $gross, $discount, $net): PromoCodeDeal {
            $promo = $this->lock($promo);

            $orderNumber = trim((string) $data['order_number']);
            $existing = PromoCodeDeal::query()->where('promo_code_id', $promo->getKey())->first();

            if ($existing instanceof PromoCodeDeal) {
                // Идемпотентный повтор: тот же человек шлёт тот же заказ —
                // отдаём уже созданную сделку, второй не заводим.
                if ($existing->reported_by === $actor->id && $existing->order_number === $orderNumber) {
                    return $existing;
                }

                throw PromoCodeException::alreadyRedeemed();
            }

            if ($promo->partner_id === null) {
                throw PromoCodeException::partnerRequired();
            }

            $this->assertUsable($promo, $actor);

            $deal = new PromoCodeDeal;
            $deal->forceFill([
                'promo_code_id' => $promo->getKey(),
                'order_number' => $orderNumber,
                'deal_date' => $this->moment($data['deal_date'] ?? null)?->toDateString() ?? now()->toDateString(),
                'gross_amount' => $gross,
                'discount_amount' => $discount,
                'net_amount' => $net,
                'currency' => (string) ($data['currency'] ?? $promo->currency ?? config('promo.defaults.currency')),
                'category' => $data['category'] ?? null,
                'comment' => $data['comment'] ?? null,
                'document_url' => $data['document_url'] ?? null,
                'reported_by' => $actor->id,
                'reported_by_role' => $role->value,
                'on_behalf_of_partner' => $onBehalf,
                'behalf_reason' => $onBehalf ? $behalfReason : null,
            ])->save();

            $this->transition($actor, $promo, PromoCodeStatus::DealReported, PromoAction::DealReported, [
                'deal_reported_at' => now(),
                'deal_reported_by' => $actor->id,
            ], changes: [
                'order_number' => $orderNumber,
                'gross_amount' => $gross,
                'discount_amount' => $discount,
                'net_amount' => $net,
                'on_behalf_of_partner' => $onBehalf,
            ]);

            if ($onBehalf) {
                // Отдельное событие журнала: «куратор внёс за партнёра» должно
                // находиться выборкой, а не вычитыванием флага из diff'а.
                $this->audit->log(
                    action: PromoAction::DealReportedOnBehalf,
                    subjectType: 'promo_code_deal',
                    subjectId: (string) $deal->getKey(),
                    actor: $actor,
                    promoCodeId: (string) $promo->getKey(),
                    reason: $behalfReason,
                );
            }

            PromoDealReported::dispatch($deal->fresh(['promoCode']));

            return $deal;
        });
    }

    /**
     * Ответ клиента: подтверждение или спор.
     *
     * Подтверждение переводит код в `client_confirmed`, а не в `closed`:
     * закрытие — акт платформы, и оно остаётся отдельным шагом.
     */
    public function respondAsClient(
        User $client,
        PromoCode $promo,
        PromoClientResponse $response,
        ?string $comment = null,
    ): PromoCodeDeal {
        return DB::transaction(function () use ($client, $promo, $response, $comment): PromoCodeDeal {
            $promo = $this->lock($promo);
            $deal = $promo->deal;

            if (! $deal instanceof PromoCodeDeal) {
                throw new PromoCodeException('По этому промокоду сделка ещё не заявлена.', 'PROMO_DEAL_MISSING');
            }

            // Идемпотентность: повторный тот же ответ ничего не меняет.
            if ($deal->client_response === $response) {
                return $deal;
            }

            if ($deal->client_response !== null) {
                throw PromoCodeException::locked('Вы уже ответили по этой сделке. Изменение ответа разбирает администратор.');
            }

            $target = $response->isConfirmation()
                ? PromoCodeStatus::ClientConfirmed
                : PromoCodeStatus::Disputed;

            $deal->forceFill([
                'client_response' => $response->value,
                'client_responded_at' => now(),
                'client_comment' => $comment,
            ])->save();

            if ($response->isConfirmation()) {
                $this->transition($client, $promo, $target, PromoAction::ClientConfirmed, [
                    'client_confirmed_at' => now(),
                ]);

                PromoDealConfirmed::dispatch($deal->fresh(['promoCode']), false);
            } else {
                $this->transition($client, $promo, $target, PromoAction::Disputed, [
                    'disputed_at' => now(),
                ], reason: $response->label());

                PromoDealDisputed::dispatch($deal->fresh(['promoCode']));
            }

            return $deal->fresh();
        });
    }

    /**
     * Административное подтверждение без ответа клиента.
     *
     * Требует основания — не для порядка: это единственный путь, на котором
     * сделка становится подтверждённой без второй стороны, и он обязан
     * оставлять след с именем человека и причиной.
     *
     * Право на это действие (`promo.confirm`) есть только у суперадмина.
     * У куратора его нет намеренно — см. App\Enums\Role.
     */
    public function confirmAsAdmin(User $admin, PromoCode $promo, string $reason): PromoCodeDeal
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw PromoCodeException::reasonRequired();
        }

        return DB::transaction(function () use ($admin, $promo, $reason): PromoCodeDeal {
            $promo = $this->lock($promo);
            $deal = $promo->deal;

            if (! $deal instanceof PromoCodeDeal) {
                throw new PromoCodeException('По этому промокоду сделка ещё не заявлена.', 'PROMO_DEAL_MISSING');
            }

            if ($promo->status === PromoCodeStatus::ClientConfirmed) {
                return $deal;
            }

            $deal->forceFill([
                'confirmed_at' => now(),
                'confirmed_by' => $admin->id,
                'confirmation_reason' => $reason,
            ])->save();

            $this->transition($admin, $promo, PromoCodeStatus::ClientConfirmed, PromoAction::AdminConfirmed, [
                'confirmed_at' => now(),
                'confirmed_by' => $admin->id,
            ], reason: $reason);

            PromoDealConfirmed::dispatch($deal->fresh(['promoCode']), true);

            return $deal->fresh();
        });
    }

    /**
     * Закрытие сделки платформой.
     *
     * Отдельный шаг после подтверждения, и он требует способности
     * `promo.confirm`. Так «сделка подтверждена стороной» и «платформа приняла
     * её в расчёт вознаграждения» остаются двумя разными записями с разными
     * акторами — а куратор не может выполнить ни одну из них.
     *
     * Идемпотентно: повторный вызов возвращает ту же сделку и не порождает
     * второго начисления.
     */
    public function close(User $actor, PromoCode $promo): PromoCodeDeal
    {
        return DB::transaction(function () use ($actor, $promo): PromoCodeDeal {
            $promo = $this->lock($promo);
            $deal = $promo->deal;

            if (! $deal instanceof PromoCodeDeal) {
                throw new PromoCodeException('По этому промокоду сделка ещё не заявлена.', 'PROMO_DEAL_MISSING');
            }

            if ($promo->status === PromoCodeStatus::Closed) {
                return $deal;
            }

            $deal->forceFill(['closed_at' => now(), 'closed_by' => $actor->id])->save();

            $this->transition($actor, $promo, PromoCodeStatus::Closed, PromoAction::Closed, [
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ]);

            PromoDealClosed::dispatch($deal->fresh(['promoCode', 'promoCode.client', 'promoCode.attribution']));

            return $deal->fresh();
        });
    }

    /** Отмена промокода или заказа. Требует основания. */
    public function cancel(User $actor, PromoCode $promo, string $reason): PromoCode
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw PromoCodeException::reasonRequired();
        }

        return DB::transaction(function () use ($actor, $promo, $reason): PromoCode {
            $promo = $this->lock($promo);

            if ($promo->status === PromoCodeStatus::Cancelled) {
                return $promo;
            }

            return $this->transition($actor, $promo, PromoCodeStatus::Cancelled, PromoAction::Cancelled, [
                'cancelled_at' => now(),
            ], reason: $reason);
        });
    }

    /**
     * Возврат по закрытой сделке.
     *
     * Начисление куратору не удаляется — слушатель события создаёт
     * сторнирующую запись. История остаётся полной.
     */
    public function refund(User $actor, PromoCode $promo, string $reason): PromoCode
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw PromoCodeException::reasonRequired();
        }

        return DB::transaction(function () use ($actor, $promo, $reason): PromoCode {
            $promo = $this->lock($promo);

            if ($promo->status === PromoCodeStatus::Refunded) {
                return $promo;
            }

            $promo = $this->transition($actor, $promo, PromoCodeStatus::Refunded, PromoAction::Refunded, [
                'refunded_at' => now(),
            ], reason: $reason);

            $deal = $promo->deal;

            if ($deal instanceof PromoCodeDeal) {
                PromoDealRefunded::dispatch($deal, $reason);
            }

            return $promo;
        });
    }

    /**
     * Пометить код истёкшим.
     *
     * Актора нет: срок истекает сам. В журнале такое событие остаётся
     * без `actor_id` — это честнее, чем приписать его тому, кто первым
     * открыл страницу.
     */
    public function expire(PromoCode $promo, ?User $actor = null): PromoCode
    {
        return DB::transaction(function () use ($promo, $actor): PromoCode {
            $promo = $this->lock($promo);

            if ($promo->status === PromoCodeStatus::Expired || $promo->status->isTerminal()) {
                return $promo;
            }

            return $this->transition($actor, $promo, PromoCodeStatus::Expired, PromoAction::Expired, [
                'expired_at' => now(),
            ]);
        });
    }

    /**
     * Применить переход состояния.
     *
     * Единственная дверь: любое изменение `status` проходит здесь, поэтому
     * недопустимый переход невозможен нигде — включая будущие маршруты.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $changes
     */
    private function transition(
        ?User $actor,
        PromoCode $promo,
        PromoCodeStatus $to,
        PromoAction $action,
        array $attributes = [],
        ?array $changes = null,
        ?string $reason = null,
    ): PromoCode {
        $from = $promo->status;

        if (! $from->canTransitionTo($to)) {
            throw PromoCodeException::transition($from, $to);
        }

        $promo->forceFill($attributes + ['status' => $to->value])->save();

        $this->audit->log(
            action: $action,
            subjectType: 'promo_code',
            subjectId: (string) $promo->getKey(),
            actor: $actor,
            promoCodeId: (string) $promo->getKey(),
            from: $from,
            to: $to,
            changes: $changes,
            reason: $reason,
        );

        return $promo;
    }

    /**
     * Перечитать строку под блокировкой.
     *
     * `lockForUpdate` защищает от гонки на СУБД, которые её поддерживают
     * (MySQL в проде). В sqlite он игнорируется, поэтому единственность сделки
     * держится не им, а уникальным индексом `promo_code_deals.promo_code_id` —
     * он работает одинаково везде.
     */
    private function lock(PromoCode $promo): PromoCode
    {
        $locked = PromoCode::query()
            ->whereKey($promo->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof PromoCode) {
            throw new PromoCodeException('Промокод не найден.', 'PROMO_NOT_FOUND', 404);
        }

        return $locked;
    }

    /**
     * Код пригоден к работе: не истёк, вступил в силу, состояние позволяет.
     *
     * Истёкший код здесь же переводится в `expired` — иначе он оставался бы
     * «активированным» в списках до следующего прогона команды, и партнёр
     * видел бы предложение, которого больше нет.
     */
    private function assertUsable(PromoCode $promo, ?User $actor = null): void
    {
        $this->assertNotExpired($promo, $actor);

        if (! $promo->hasStarted()) {
            throw PromoCodeException::notStarted();
        }

        if (! $promo->status->isRedeemable()) {
            throw PromoCodeException::notRedeemable($promo->status);
        }
    }

    private function assertNotExpired(PromoCode $promo, ?User $actor = null): void
    {
        if ($promo->status === PromoCodeStatus::Expired || $promo->isPastExpiry()) {
            throw PromoCodeException::expired();
        }
    }

    /**
     * Перевести просроченный код в `expired` ПЕРЕД основной операцией.
     *
     * Отдельной транзакцией и до открытия основной — намеренно. Внутри
     * основной этот перевод откатился бы вместе с исключением «код истёк»,
     * и код навсегда остался бы «активированным»: в списке партнёра висело бы
     * предложение, которого больше нет, а следующая попытка снова падала бы
     * и снова ничего не записывала.
     */
    private function expireIfPastDue(PromoCode $promo, ?User $actor = null): void
    {
        if (! $promo->isPastExpiry() || $promo->status->isTerminal()) {
            return;
        }

        // Заявленную и подтверждённую сделку срок кода уже не отменяет:
        // товар куплен, скидка предоставлена.
        if (! $promo->status->isRedeemable()) {
            return;
        }

        DB::transaction(function () use ($promo, $actor): void {
            $locked = $this->lock($promo);

            if (! $locked->isPastExpiry() || ! $locked->status->isRedeemable()) {
                return;
            }

            $this->transition($actor, $locked, PromoCodeStatus::Expired, PromoAction::Expired, [
                'expired_at' => now(),
            ]);
        });
    }

    /**
     * Предмет скидки должен существовать, если тип на него ссылается.
     *
     * Без проверки промокод указывал бы на удалённую категорию, и клиент
     * увидел бы скидку на то, чего нет. Для `service` и `custom` ссылки нет
     * вовсе — там достаточно названия, которое видит человек.
     */
    private function assertSubjectExists(PromoSubjectType $type, ?string $subjectId): void
    {
        $table = $type->table();

        if ($table === null || $subjectId === null || $subjectId === '') {
            return;
        }

        if (! DB::table($table)->where('id', $subjectId)->exists()) {
            throw new PromoCodeException(
                sprintf('%s: запись не найдена.', $type->label()),
                'PROMO_SUBJECT_NOT_FOUND',
            );
        }
    }

    private function assertDiscount(PromoDiscountType $type, string $value): void
    {
        if (bccomp($value, '0', self::SCALE) <= 0) {
            throw new PromoCodeException('Размер скидки должен быть больше нуля.', 'PROMO_DISCOUNT_INVALID');
        }

        if ($type === PromoDiscountType::Percent && bccomp($value, '100', self::SCALE) > 0) {
            throw new PromoCodeException('Процентная скидка не может превышать 100 %.', 'PROMO_DISCOUNT_INVALID');
        }
    }

    /**
     * Денежное значение как строка фиксированной точности.
     *
     * Через строку и bcmath, минуя float: `0.1 + 0.2` в двоичной дроби
     * не равно `0.3`, и в деньгах эта разница становится копейкой, которая
     * не сходится в отчёте.
     */
    private function money(mixed $value, string $label): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw new PromoCodeException(sprintf('%s: укажите число.', $label), 'PROMO_AMOUNT_INVALID');
        }

        if (is_float($value)) {
            // Float до сервиса доходить не должен: значение приходит строкой
            // из JSON и такой же обязано дойти до bcmath. Приводим через
            // фиксированный формат, чтобы не тащить дальше двоичную дробь.
            $value = number_format($value, self::SCALE, '.', '');
        }

        return bcadd((string) $value, '0', self::SCALE);
    }

    private function moment(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw new PromoCodeException('Некорректная дата.', 'PROMO_DATE_INVALID');
        }
    }

    /**
     * Вставка с уникальным кодом.
     *
     * Повтор при нарушении уникального индекса — тот же приём, что
     * в `Invoice::createWithUniqueNumber`: проверка «свободен ли код» и вставка
     * не атомарны, и на гонке выигрывает индекс, а не проверка.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertWithUniqueCode(array $attributes, int $attempts = 5): PromoCode
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $promo = new PromoCode;
                $promo->forceFill($attributes + ['code' => $this->generator->generate()])->save();

                return $promo;
            } catch (UniqueConstraintViolationException|QueryException $e) {
                $message = strtolower($e->getMessage());

                if (! $e instanceof UniqueConstraintViolationException
                    && ! str_contains($message, 'unique')
                    && ! str_contains($message, 'duplicate')) {
                    throw $e;
                }

                $lastException = $e;
            }
        }

        throw $lastException;
    }
}
