<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Enums\PromoClientResponse;
use App\Enums\PromoCodeStatus;
use App\Enums\Role;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\PromoCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPromoSchema;
use Tests\TestCase;

/**
 * Общая обвязка тестов промокодов.
 *
 * Четыре роли и один промокод — то, вокруг чего крутится каждая проверка.
 * Хелперы намеренно ходят через сервис, а не пишут в таблицы напрямую:
 * тест, собравший состояние в обход state machine, проверял бы схему,
 * а не поведение.
 */
abstract class PromoTestCase extends TestCase
{
    use CreatesPromoSchema, RefreshDatabase;

    protected User $client;

    protected User $partner;

    protected User $curator;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPromoSchema();

        $this->client = $this->promoUser('client@example.test', Role::Client, '+7 999 111-22-33');
        $this->partner = $this->promoUser('partner@example.test', Role::Partner, '+7 999 222-33-44');
        $this->curator = $this->promoUser('curator@example.test', Role::Curator);
        $this->admin = $this->promoUser('admin@example.test', Role::Superadmin);
    }

    protected function service(): PromoCodeService
    {
        return app(PromoCodeService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makePromo(array $overrides = []): PromoCode
    {
        return $this->service()->create(
            $overrides['actor'] ?? $this->curator,
            $overrides['client'] ?? $this->client,
            array_merge([
                'curator' => $this->curator,
                'partner' => $this->partner,
                'subject_type' => 'category',
                'subject_title' => 'Кухни на заказ',
                'discount_type' => 'percent',
                'discount_value' => '10',
            ], $overrides),
        );
    }

    /** Промокод, доведённый до состояния «сделка заявлена» партнёром. */
    protected function promoWithReportedDeal(?PromoCode $promo = null): PromoCode
    {
        $promo ??= $this->makePromo();

        $this->service()->activate($this->curator, $promo);
        $this->service()->markPresented($this->partner, $promo->fresh());
        $this->service()->reportDeal($this->partner, $promo->fresh(), $this->dealPayload());

        return $promo->fresh();
    }

    /** Промокод, прошедший весь путь до закрытой сделки. */
    protected function closedPromo(): PromoCode
    {
        $promo = $this->promoWithReportedDeal();

        $this->service()->respondAsClient(
            $this->client,
            $promo,
            PromoClientResponse::Confirmed,
        );

        $this->service()->close($this->admin, $promo->fresh());

        $promo = $promo->fresh();
        $this->assertSame(PromoCodeStatus::Closed, $promo->status);

        return $promo;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function dealPayload(array $overrides = []): array
    {
        return array_merge([
            'order_number' => 'ДГ-1024',
            'deal_date' => now()->toDateString(),
            'gross_amount' => '100000.00',
            'discount_amount' => '10000.00',
            'currency' => 'RUB',
            'category' => 'Кухни',
        ], $overrides);
    }
}
