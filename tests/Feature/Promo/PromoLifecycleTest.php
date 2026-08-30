<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Enums\PromoClientResponse;
use App\Enums\PromoCodeStatus;
use App\Enums\Role;
use App\Exceptions\PromoCodeException;
use App\Models\PromoCodeDeal;

/**
 * Путь промокода от создания до закрытой сделки — и запреты по дороге.
 */
class PromoLifecycleTest extends PromoTestCase
{
    /** Сквозной сценарий: он же критерий готовности из задачи. */
    public function test_promo_code_travels_the_whole_path_to_a_closed_deal(): void
    {
        $promo = $this->makePromo();
        $this->assertSame(PromoCodeStatus::Created, $promo->status);

        $this->service()->activate($this->curator, $promo);
        $this->assertSame(PromoCodeStatus::Activated, $promo->fresh()->status);

        $this->service()->markPresented($this->partner, $promo->fresh());
        $this->assertSame(PromoCodeStatus::Presented, $promo->fresh()->status);

        $this->service()->markOrderCreated($this->partner, $promo->fresh());
        $this->assertSame(PromoCodeStatus::OrderCreated, $promo->fresh()->status);

        $deal = $this->service()->reportDeal($this->partner, $promo->fresh(), $this->dealPayload());
        $this->assertSame(PromoCodeStatus::DealReported, $promo->fresh()->status);
        // Итог считает сервер, а не фронтенд.
        $this->assertSame('90000.00', $deal->net_amount);

        $this->service()->respondAsClient($this->client, $promo->fresh(), PromoClientResponse::Confirmed);
        $this->assertSame(PromoCodeStatus::ClientConfirmed, $promo->fresh()->status);

        $this->service()->close($this->admin, $promo->fresh());
        $this->assertSame(PromoCodeStatus::Closed, $promo->fresh()->status);
        $this->assertNotNull($promo->fresh()->closed_at);
    }

    public function test_expired_code_cannot_be_presented_and_is_marked_expired(): void
    {
        $promo = $this->makePromo(['expires_at' => now()->addDay()->toIso8601String()]);
        $this->service()->activate($this->curator, $promo);

        $this->travel(2)->days();

        try {
            $this->service()->markPresented($this->partner, $promo->fresh());
            $this->fail('Истёкший код был предъявлен.');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_EXPIRED', $e->errorCode());
        }

        // Обращение к истёкшему коду переводит его в expired — иначе он остался
        // бы «активированным» в списке партнёра.
        $this->assertSame(PromoCodeStatus::Expired, $promo->fresh()->status);
    }

    public function test_expired_code_cannot_be_redeemed(): void
    {
        $promo = $this->makePromo(['expires_at' => now()->addDay()->toIso8601String()]);
        $this->service()->activate($this->curator, $promo);
        $this->travel(2)->days();

        $this->expectException(PromoCodeException::class);

        $this->service()->reportDeal($this->partner, $promo->fresh(), $this->dealPayload());
    }

    public function test_cancelled_code_cannot_be_redeemed(): void
    {
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);
        $this->service()->cancel($this->curator, $promo->fresh(), 'Клиент отказался');

        try {
            $this->service()->reportDeal($this->partner, $promo->fresh(), $this->dealPayload());
            $this->fail('Отменённый код был погашен.');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_NOT_REDEEMABLE', $e->errorCode());
        }

        $this->assertNull(PromoCodeDeal::query()->where('promo_code_id', $promo->getKey())->first());
    }

    public function test_code_not_yet_in_force_cannot_be_presented(): void
    {
        $promo = $this->makePromo([
            'starts_at' => now()->addDays(3)->toIso8601String(),
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]);
        $this->service()->activate($this->curator, $promo);

        try {
            $this->service()->markPresented($this->partner, $promo->fresh());
            $this->fail('Код применён до начала срока.');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_NOT_STARTED', $e->errorCode());
        }
    }

    /**
     * Активированный код — обещание, данное клиенту и партнёру. Незаметно
     * переназначить его другой организации нельзя.
     */
    public function test_activated_code_cannot_be_reassigned_to_another_partner(): void
    {
        $other = $this->promoUser('other-partner@example.test', Role::Partner);

        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        try {
            $this->service()->assignPartner($this->curator, $promo->fresh(), $other);
            $this->fail('Партнёр активированного кода был заменён.');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_LOCKED', $e->errorCode());
        }

        $this->assertSame($this->partner->id, $promo->fresh()->partner_id);
    }

    public function test_activated_code_terms_cannot_be_changed(): void
    {
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        $this->expectException(PromoCodeException::class);

        $this->service()->updateTerms($this->curator, $promo->fresh(), ['discount_value' => '50']);
    }

    /** Повторная активация — не ошибка и не второе событие смены статуса. */
    public function test_activation_is_idempotent(): void
    {
        $promo = $this->makePromo();

        $this->service()->activate($this->curator, $promo);
        $first = $promo->fresh()->activated_at;

        $this->travel(1)->minute();
        $this->service()->activate($this->curator, $promo->fresh());

        $this->assertEquals($first, $promo->fresh()->activated_at);
    }

    /** Промокод без партнёра погасить не от кого. */
    public function test_deal_requires_an_assigned_partner(): void
    {
        $promo = $this->makePromo(['partner' => null]);
        $this->service()->activate($this->curator, $promo);

        try {
            $this->service()->reportDeal($this->curator, $promo->fresh(), $this->dealPayload([
                'on_behalf_of_partner' => true,
                'behalf_reason' => 'Партнёр не пользуется кабинетом',
            ]));
            $this->fail('Сделка заявлена без партнёра.');
        } catch (PromoCodeException $e) {
            $this->assertSame('PROMO_PARTNER_REQUIRED', $e->errorCode());
        }
    }

    public function test_scheduled_command_expires_untouched_codes(): void
    {
        $promo = $this->makePromo(['expires_at' => now()->addDay()->toIso8601String()]);
        $this->service()->activate($this->curator, $promo);

        $this->travel(2)->days();

        $this->artisan('promo:expire')->assertSuccessful();

        $this->assertSame(PromoCodeStatus::Expired, $promo->fresh()->status);
    }

    /** Заявленную сделку срок кода уже не отменяет: товар куплен. */
    public function test_reported_deal_is_not_expired_by_the_command(): void
    {
        $promo = $this->makePromo(['expires_at' => now()->addDay()->toIso8601String()]);
        $promo = $this->promoWithReportedDeal($promo);

        $this->travel(3)->days();
        $this->artisan('promo:expire')->assertSuccessful();

        $this->assertSame(PromoCodeStatus::DealReported, $promo->fresh()->status);
    }
}
