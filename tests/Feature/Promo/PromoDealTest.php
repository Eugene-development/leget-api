<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Enums\PromoClientResponse;
use App\Enums\PromoCodeStatus;
use App\Enums\Role;
use App\Exceptions\PromoCodeException;
use App\Models\PromoCode;
use App\Models\PromoCodeDeal;
use App\Models\User;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Заявление сделки, ответ клиента и защита от двойного погашения.
 *
 * Здесь же проверяется главное свойство задачи: действие куратора не создаёт
 * окончательно подтверждённую сделку.
 */
class PromoDealTest extends PromoTestCase
{
    public function test_partner_reports_a_deal_and_server_recomputes_the_total(): void
    {
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        $deal = $this->service()->reportDeal($this->partner, $promo->fresh(), $this->dealPayload([
            // Врать про итог бесполезно: сервер считает его сам.
            'net_amount' => '1.00',
        ]));

        $this->assertSame('100000.00', $deal->gross_amount);
        $this->assertSame('10000.00', $deal->discount_amount);
        $this->assertSame('90000.00', $deal->net_amount);
        $this->assertFalse($deal->on_behalf_of_partner);
    }

    public function test_discount_larger_than_the_order_is_rejected(): void
    {
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        try {
            $this->service()->reportDeal($this->partner, $promo->fresh(), $this->dealPayload([
                'gross_amount' => '1000.00',
                'discount_amount' => '1500.00',
            ]));
            $this->fail('Скидка больше суммы заказа принята.');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_DISCOUNT_EXCEEDS_ORDER', $e->errorCode());
        }
    }

    public function test_zero_order_amount_is_rejected(): void
    {
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        $this->expectException(PromoCodeException::class);

        $this->service()->reportDeal($this->partner, $promo->fresh(), $this->dealPayload([
            'gross_amount' => '0',
            'discount_amount' => '0',
        ]));
    }

    public function test_minimum_order_amount_is_enforced(): void
    {
        $promo = $this->makePromo(['minimum_order_amount' => '50000']);
        $this->service()->activate($this->curator, $promo);

        try {
            $this->service()->reportDeal($this->partner, $promo->fresh(), $this->dealPayload([
                'gross_amount' => '10000.00',
                'discount_amount' => '1000.00',
            ]));
            $this->fail('Заказ ниже минимальной суммы принят.');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_MIN_ORDER_NOT_MET', $e->errorCode());
        }
    }

    /**
     * Куратор обязан объявить, что действует за партнёра, и указать основание.
     * Иначе «сделку внёс партнёр» и «сделку внёс заинтересованный куратор»
     * выглядели бы в журнале одинаково.
     */
    public function test_curator_must_declare_acting_on_behalf_of_the_partner(): void
    {
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        try {
            $this->service()->reportDeal($this->curator, $promo->fresh(), $this->dealPayload());
            $this->fail('Куратор внёс сделку без отметки «от имени партнёра».');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_BEHALF_REQUIRED', $e->errorCode());
        }
    }

    public function test_curator_must_give_a_reason_when_acting_on_behalf(): void
    {
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        try {
            $this->service()->reportDeal($this->curator, $promo->fresh(), $this->dealPayload([
                'on_behalf_of_partner' => true,
                'behalf_reason' => '   ',
            ]));
            $this->fail('Куратор внёс сделку без основания.');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_REASON_REQUIRED', $e->errorCode());
        }
    }

    /** Главное свойство: куратор доводит код до «заявлена» и останавливается. */
    public function test_curator_action_does_not_produce_a_confirmed_deal(): void
    {
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        $deal = $this->service()->reportDeal($this->curator, $promo->fresh(), $this->dealPayload([
            'on_behalf_of_partner' => true,
            'behalf_reason' => 'Партнёр не пользуется кабинетом, продиктовал по телефону',
        ]));

        $promo = $promo->fresh();

        $this->assertSame(PromoCodeStatus::DealReported, $promo->status);
        $this->assertNull($promo->client_confirmed_at);
        $this->assertNull($promo->closed_at);
        $this->assertNull($deal->client_response);
        $this->assertTrue($deal->on_behalf_of_partner);
        $this->assertNotEmpty($deal->behalf_reason);

        // И закрыть её куратор тоже не может: переход запрещён самой моделью.
        $this->expectException(PromoCodeException::class);
        $this->service()->close($this->curator, $promo);
    }

    public function test_client_confirms_their_own_deal(): void
    {
        $promo = $this->promoWithReportedDeal();

        $deal = $this->service()->respondAsClient($this->client, $promo, PromoClientResponse::Confirmed);

        $this->assertSame(PromoClientResponse::Confirmed, $deal->client_response);
        $this->assertSame(PromoCodeStatus::ClientConfirmed, $promo->fresh()->status);
        $this->assertNotNull($promo->fresh()->client_confirmed_at);
    }

    #[DataProvider('disputeReasons')]
    public function test_dispute_moves_the_deal_to_disputed(string $reason): void
    {
        $promo = $this->promoWithReportedDeal();

        $deal = $this->service()->respondAsClient(
            $this->client,
            $promo,
            PromoClientResponse::from($reason),
            'Скидку не дали',
        );

        $this->assertSame(PromoCodeStatus::Disputed, $promo->fresh()->status);
        $this->assertSame($reason, $deal->client_response->value);
        $this->assertNotNull($promo->fresh()->disputed_at);
        $this->assertNull($promo->fresh()->client_confirmed_at);
    }

    /** @return array<string, array{string}> */
    public static function disputeReasons(): array
    {
        return [
            'сумма неверна' => ['amount_wrong'],
            'скидка не предоставлена' => ['discount_missing'],
            'сделки не было' => ['no_deal'],
            'заказ отменён' => ['order_cancelled'],
        ];
    }

    /** Одна сделка на код — то, что не даёт погасить его дважды. */
    public function test_a_code_cannot_be_redeemed_twice(): void
    {
        $promo = $this->promoWithReportedDeal();

        try {
            $this->service()->reportDeal($this->partner, $promo->fresh(), $this->dealPayload([
                'order_number' => 'ДГ-2048',
            ]));
            $this->fail('По одному коду заведены две сделки.');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_ALREADY_REDEEMED', $e->errorCode());
        }

        $this->assertSame(1, PromoCodeDeal::query()->where('promo_code_id', $promo->getKey())->count());
    }

    /**
     * Повторный запрос с теми же данными — не ошибка и не вторая сделка.
     * Именно так выглядит потерянный ответ и повтор с фронтенда.
     */
    public function test_repeating_the_same_report_is_idempotent(): void
    {
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        $first = $this->service()->reportDeal($this->partner, $promo->fresh(), $this->dealPayload());
        $second = $this->service()->reportDeal($this->partner, $promo->fresh(), $this->dealPayload());

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, PromoCodeDeal::query()->count());
    }

    /**
     * Два одновременных погашения: параллелизм в тестах на sqlite недостижим,
     * но настоящая гарантия — уникальный индекс, и её можно проверить прямо.
     */
    public function test_unique_index_is_what_prevents_a_second_deal(): void
    {
        $promo = $this->promoWithReportedDeal();
        $deal = $promo->fresh()->deal;

        $this->expectException(QueryException::class);

        $duplicate = new PromoCodeDeal;
        $duplicate->forceFill([
            'promo_code_id' => $promo->getKey(),
            'order_number' => 'ДГ-9999',
            'deal_date' => now()->toDateString(),
            'gross_amount' => '1.00',
            'discount_amount' => '0.00',
            'net_amount' => '1.00',
            'currency' => 'RUB',
            'reported_by' => $this->partner->id,
            'reported_by_role' => 'partner',
        ])->save();

        $this->assertNotNull($deal);
    }

    public function test_client_cannot_answer_twice(): void
    {
        $promo = $this->promoWithReportedDeal();
        $this->service()->respondAsClient($this->client, $promo, PromoClientResponse::Confirmed);

        try {
            $this->service()->respondAsClient($this->client, $promo->fresh(), PromoClientResponse::NoDeal);
            $this->fail('Клиент переписал свой ответ.');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_LOCKED', $e->errorCode());
        }
    }

    /** Тот же ответ повторно — идемпотентно, без второго перехода. */
    public function test_repeating_the_same_client_answer_changes_nothing(): void
    {
        $promo = $this->promoWithReportedDeal();

        $first = $this->service()->respondAsClient($this->client, $promo, PromoClientResponse::Confirmed);
        $second = $this->service()->respondAsClient($this->client, $promo->fresh(), PromoClientResponse::Confirmed);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(PromoCodeStatus::ClientConfirmed, $promo->fresh()->status);
    }

    /**
     * Административное подтверждение — единственный обход второй стороны,
     * и без основания оно не проходит.
     */
    public function test_administrative_confirmation_requires_a_reason(): void
    {
        $promo = $this->promoWithReportedDeal();

        try {
            $this->service()->confirmAsAdmin($this->admin, $promo, '  ');
            $this->fail('Админ подтвердил сделку без основания.');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_REASON_REQUIRED', $e->errorCode());
        }

        $deal = $this->service()->confirmAsAdmin($this->admin, $promo->fresh(), 'Клиент подтвердил по телефону');

        $this->assertSame(PromoCodeStatus::ClientConfirmed, $promo->fresh()->status);
        $this->assertSame('Клиент подтвердил по телефону', $deal->confirmation_reason);
        $this->assertSame($this->admin->id, $deal->confirmed_by);
    }

    /** Закрытие идемпотентно: второй вызов не создаёт второй сделки. */
    public function test_closing_twice_is_idempotent(): void
    {
        $promo = $this->closedPromo();

        $deal = $this->service()->close($this->admin, $promo->fresh());

        $this->assertSame(1, PromoCodeDeal::query()->count());
        $this->assertSame(PromoCodeStatus::Closed, $promo->fresh()->status);
        $this->assertNotNull($deal->closed_at);
    }

    public function test_deal_cannot_be_reported_for_someone_elses_partner(): void
    {
        $stranger = $this->promoUser('stranger@example.test', Role::Partner);
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        // Сервис доверяет актору, которого разрешил контроллер; здесь проверяем
        // саму границу маршрута — чужой промокод недостижим.
        $this->assertNull(
            PromoCode::query()->visibleToPartner($stranger->id)->whereKey($promo->getKey())->first(),
        );

        $this->assertInstanceOf(User::class, $stranger);
    }
}
