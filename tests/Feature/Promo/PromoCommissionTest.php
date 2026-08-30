<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Enums\CommissionEntryType;
use App\Enums\PromoClientResponse;
use App\Models\CuratorCommission;
use App\Services\CuratorCommissionService;
use Illuminate\Database\QueryException;

/**
 * Вознаграждение куратора: когда начисляется, когда нет и что происходит
 * при возврате.
 */
class PromoCommissionTest extends PromoTestCase
{
    /** Начисление не происходит по действию куратора — только по закрытию. */
    public function test_curator_action_alone_accrues_nothing(): void
    {
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);
        $this->service()->reportDeal($this->curator, $promo->fresh(), $this->dealPayload([
            'on_behalf_of_partner' => true,
            'behalf_reason' => 'Партнёр продиктовал по телефону',
        ]));

        $this->assertSame(0, CuratorCommission::query()->count());

        // И после подтверждения клиентом — тоже: закрытие остаётся отдельным
        // актом платформы.
        $this->service()->respondAsClient($this->client, $promo->fresh(), PromoClientResponse::Confirmed);
        $this->assertSame(0, CuratorCommission::query()->count());
    }

    public function test_closing_a_deal_accrues_exactly_one_entry(): void
    {
        $this->closedPromo();

        $this->assertSame(1, CuratorCommission::query()->count());

        $entry = CuratorCommission::query()->firstOrFail();
        $this->assertSame(CommissionEntryType::Accrual, $entry->entry_type);
        $this->assertSame($this->curator->id, (int) $entry->curator_id);
    }

    /** Повторное закрытие не создаёт второго начисления. */
    public function test_second_close_does_not_accrue_twice(): void
    {
        $promo = $this->closedPromo();

        $this->service()->close($this->admin, $promo->fresh());
        app(CuratorCommissionService::class)->accrue($promo->fresh()->deal);

        $this->assertSame(1, CuratorCommission::query()->count());
    }

    /**
     * Формулы платформа не согласовала — сумма остаётся пустой, а сделка
     * всё равно учтена. Выдуманная ставка была бы хуже пустой колонки.
     */
    public function test_amount_is_null_while_the_rule_is_unconfigured(): void
    {
        $this->closedPromo();

        $entry = CuratorCommission::query()->firstOrFail();

        $this->assertNull($entry->amount);
        $this->assertSame(CuratorCommissionService::RULE_UNCONFIGURED, $entry->rule);
    }

    public function test_fixed_rule_is_applied_and_snapshotted(): void
    {
        config()->set('promo.commission.fixed_per_closed_deal', '1500');

        $this->closedPromo();

        $entry = CuratorCommission::query()->firstOrFail();

        $this->assertSame(CuratorCommissionService::RULE_FIXED, $entry->rule);
        $this->assertSame(0, bccomp('1500.00', (string) $entry->amount, 2));
        $this->assertSame('1500.00', $entry->rule_snapshot['fixed_per_closed_deal']);

        // Снимок правила: изменение настройки не переписывает прошлое.
        config()->set('promo.commission.fixed_per_closed_deal', '9999');

        $this->assertSame('1500.00', $entry->fresh()->rule_snapshot['fixed_per_closed_deal']);
        $this->assertSame(0, bccomp('1500.00', (string) $entry->fresh()->amount, 2));
    }

    public function test_percent_rule_computes_from_the_net_amount(): void
    {
        config()->set('promo.commission.percent_of_platform_commission', '2.5');

        $this->closedPromo();

        $entry = CuratorCommission::query()->firstOrFail();

        // 90 000 × 2,5 % = 2 250
        $this->assertSame(0, bccomp('2250.00', (string) $entry->amount, 2));
        $this->assertSame(CuratorCommissionService::RULE_PERCENT, $entry->rule);
    }

    /**
     * Возврат исключает сделку из текущего вознаграждения и создаёт
     * корректировку — история не удаляется.
     */
    public function test_refund_creates_a_reversal_instead_of_deleting_history(): void
    {
        config()->set('promo.commission.fixed_per_closed_deal', '1500');

        $promo = $this->closedPromo();

        $this->service()->refund($this->admin, $promo->fresh(), 'Клиент вернул товар');

        $entries = CuratorCommission::query()->orderBy('entry_type')->get();

        $this->assertCount(2, $entries);
        $this->assertSame(CommissionEntryType::Accrual, $entries[0]->entry_type);
        $this->assertSame(CommissionEntryType::Reversal, $entries[1]->entry_type);
        $this->assertSame(0, bccomp('-1500.00', (string) $entries[1]->amount, 2));
        $this->assertSame('Клиент вернул товар', $entries[1]->reason);

        // Сумма к выплате обнулилась, но обе записи на месте.
        $report = app(CuratorCommissionService::class)->report($this->curator);
        $this->assertSame(0, bccomp('0.00', (string) $report['commission_total'], 2));
        $this->assertSame(1, $report['reversals']);
    }

    public function test_refund_is_not_reversed_twice(): void
    {
        config()->set('promo.commission.fixed_per_closed_deal', '1500');

        $promo = $this->closedPromo();
        $deal = $promo->fresh()->deal;

        $this->service()->refund($this->admin, $promo->fresh(), 'Возврат');
        app(CuratorCommissionService::class)->reverse($deal, 'Ещё раз');

        $this->assertSame(
            1,
            CuratorCommission::query()->where('entry_type', CommissionEntryType::Reversal->value)->count(),
        );
    }

    /** Возвращённая сделка не попадает в отчёт как закрытая. */
    public function test_refunded_deal_leaves_the_closed_report(): void
    {
        $promo = $this->closedPromo();

        $before = app(CuratorCommissionService::class)->report($this->curator);
        $this->assertSame(1, $before['closed_deals']);

        $this->service()->refund($this->admin, $promo->fresh(), 'Возврат');

        $after = app(CuratorCommissionService::class)->report($this->curator);
        $this->assertSame(0, $after['closed_deals']);
    }

    public function test_curator_report_shows_volumes_and_states_the_rule(): void
    {
        $this->closedPromo();

        $response = $this->actingAs($this->curator, 'api')
            ->getJson('/promo/curator/report')
            ->assertOk();

        $this->assertSame(1, $response->json('report.closed_deals'));
        $this->assertSame(0, bccomp('100000.00', (string) $response->json('report.deals_total'), 2));
        $this->assertSame(0, bccomp('10000.00', (string) $response->json('report.discount_total'), 2));
        $this->assertSame(0, bccomp('90000.00', (string) $response->json('report.net_total'), 2));
        // «Не настроено» и «ноль» — разные ответы.
        $this->assertFalse($response->json('report.commission_configured'));
        $this->assertNull($response->json('report.commission_total'));
    }

    /** Промокод без куратора начислять некому — и это не ошибка. */
    public function test_promo_without_a_curator_accrues_nothing(): void
    {
        $promo = $this->makePromo(['curator' => null, 'actor' => $this->admin]);
        $this->service()->activate($this->admin, $promo);
        $this->service()->reportDeal($this->partner, $promo->fresh(), $this->dealPayload());
        $this->service()->respondAsClient($this->client, $promo->fresh(), PromoClientResponse::Confirmed);
        $this->service()->close($this->admin, $promo->fresh());

        $this->assertSame(0, CuratorCommission::query()->count());
    }

    /** Денежные значения не проходят через float ни на одном участке. */
    public function test_commission_amounts_are_strings(): void
    {
        config()->set('promo.commission.fixed_per_closed_deal', '1500.55');

        $this->closedPromo();

        $entry = CuratorCommission::query()->firstOrFail();

        $this->assertIsString($entry->amount);
        $this->assertIsString($entry->deal_gross_amount);
        $this->assertSame(0, bccomp('1500.55', (string) $entry->amount, 2));
    }

    /** Уникальный индекс — то, что не даёт начислить дважды при гонке. */
    public function test_unique_index_guards_the_second_accrual(): void
    {
        $promo = $this->closedPromo();
        $existing = CuratorCommission::query()->firstOrFail();

        $this->expectException(QueryException::class);

        $duplicate = new CuratorCommission;
        $duplicate->forceFill([
            'promo_code_deal_id' => $existing->promo_code_deal_id,
            'promo_code_id' => $promo->getKey(),
            'curator_id' => $this->curator->id,
            'entry_type' => CommissionEntryType::Accrual->value,
            'rule' => CuratorCommissionService::RULE_UNCONFIGURED,
            'deal_gross_amount' => '1.00',
            'deal_discount_amount' => '0.00',
            'accrued_at' => now(),
        ])->save();
    }
}
