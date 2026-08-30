<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Enums\PromoAction;
use App\Enums\PromoClientResponse;
use App\Enums\PromoCodeStatus;
use App\Models\PromoCodeEvent;
use LogicException;

/**
 * Журнал аудита: каждое существенное действие оставляет след, и след нельзя
 * ни изменить, ни удалить.
 */
class PromoAuditTest extends PromoTestCase
{
    public function test_every_critical_action_writes_an_event(): void
    {
        $promo = $this->closedPromo();

        // pluck отдаёт значения уже приведёнными к enum (колонка кастуется),
        // поэтому сравниваем по ->value, а не по строке из БД.
        $actions = PromoCodeEvent::query()
            ->where('promo_code_id', $promo->getKey())
            ->get()
            ->map(static fn (PromoCodeEvent $event): string => $event->action->value)
            ->all();

        foreach ([
            PromoAction::Created,
            PromoAction::Activated,
            PromoAction::Presented,
            PromoAction::DealReported,
            PromoAction::ClientConfirmed,
            PromoAction::Closed,
        ] as $expected) {
            $this->assertContains($expected->value, $actions, "Нет события {$expected->value}.");
        }
    }

    public function test_event_records_actor_role_status_change_and_request_trace(): void
    {
        $promo = $this->makePromo();

        $this->actingAs($this->curator, 'api')
            ->withHeaders(['User-Agent' => 'LEGET-Test/1.0', 'X-Forwarded-For' => '203.0.113.7'])
            ->postJson("/promo/curator/codes/{$promo->getKey()}/activate")
            ->assertOk();

        $event = PromoCodeEvent::query()
            ->where('promo_code_id', $promo->getKey())
            ->where('action', PromoAction::Activated->value)
            ->firstOrFail();

        $this->assertSame($this->curator->id, (int) $event->actor_id);
        $this->assertSame('curator', $event->actor_role);
        $this->assertSame(PromoCodeStatus::Created->value, $event->from_status);
        $this->assertSame(PromoCodeStatus::Activated->value, $event->to_status);
        $this->assertSame('203.0.113.7', $event->ip_address);
        $this->assertSame('LEGET-Test/1.0', $event->user_agent);
        $this->assertNotNull($event->created_at);
    }

    /**
     * Действие куратора от имени партнёра — отдельное событие с основанием.
     * Иначе оно не отличалось бы от обычного внесения сделки партнёром.
     */
    public function test_acting_on_behalf_of_partner_is_a_separate_event_with_a_reason(): void
    {
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);
        $this->service()->reportDeal($this->curator, $promo->fresh(), $this->dealPayload([
            'on_behalf_of_partner' => true,
            'behalf_reason' => 'Партнёр не пользуется кабинетом',
        ]));

        $event = PromoCodeEvent::query()
            ->where('action', PromoAction::DealReportedOnBehalf->value)
            ->firstOrFail();

        $this->assertSame($this->curator->id, (int) $event->actor_id);
        $this->assertSame('Партнёр не пользуется кабинетом', $event->reason);
        $this->assertSame('promo_code_deal', $event->subject_type);
    }

    public function test_administrative_confirmation_stores_its_reason(): void
    {
        $promo = $this->promoWithReportedDeal();

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/promo-codes/{$promo->getKey()}/confirm", [
                'reason' => 'Клиент подтвердил по телефону, есть запись разговора',
            ])
            ->assertOk();

        $event = PromoCodeEvent::query()
            ->where('action', PromoAction::AdminConfirmed->value)
            ->firstOrFail();

        $this->assertSame($this->admin->id, (int) $event->actor_id);
        $this->assertSame('superadmin', $event->actor_role);
        $this->assertStringContainsString('запись разговора', (string) $event->reason);
    }

    public function test_dispute_is_recorded_with_the_clients_reason(): void
    {
        $promo = $this->promoWithReportedDeal();

        $this->service()->respondAsClient(
            $this->client,
            $promo,
            PromoClientResponse::DiscountMissing,
            'Скидку не применили',
        );

        $event = PromoCodeEvent::query()
            ->where('action', PromoAction::Disputed->value)
            ->firstOrFail();

        $this->assertSame($this->client->id, (int) $event->actor_id);
        $this->assertSame('client', $event->actor_role);
        $this->assertSame(PromoCodeStatus::Disputed->value, $event->to_status);
    }

    /** Просмотр рекламной аналитики — действие, а не чтение. */
    public function test_admin_card_view_is_logged_as_a_sensitive_access(): void
    {
        $promo = $this->makePromo();

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/promo-codes/{$promo->getKey()}")
            ->assertOk();

        $this->assertSame(
            1,
            PromoCodeEvent::query()->where('action', PromoAction::SensitiveViewed->value)->count(),
        );
    }

    /** Список реестра в журнал не пишется — иначе он утонул бы в логе доступа. */
    public function test_registry_listing_is_not_logged(): void
    {
        $this->makePromo();

        $this->actingAs($this->admin, 'api')->getJson('/admin/promo-codes')->assertOk();

        $this->assertSame(
            0,
            PromoCodeEvent::query()->where('action', PromoAction::SensitiveViewed->value)->count(),
        );
    }

    /** Журнал изобличает — и потому не должен поддаваться правке. */
    public function test_audit_records_cannot_be_updated_or_deleted(): void
    {
        $this->makePromo();
        $event = PromoCodeEvent::query()->firstOrFail();

        try {
            $event->update(['reason' => 'подчистили']);
            $this->fail('Запись журнала удалось изменить.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('неизменяемы', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    /** Секретов и лишних персональных данных в журнале быть не должно. */
    public function test_audit_diff_holds_no_secrets(): void
    {
        $promo = $this->closedPromo();

        foreach (PromoCodeEvent::query()->where('promo_code_id', $promo->getKey())->get() as $event) {
            $encoded = json_encode($event->changes, JSON_UNESCAPED_UNICODE) ?: '';

            $this->assertStringNotContainsString('password', $encoded);
            $this->assertStringNotContainsString('token', $encoded);
            $this->assertStringNotContainsString($this->client->email, $encoded);
        }
    }

    /** Начисление и сторно тоже попадают в журнал. */
    public function test_commission_entries_are_audited(): void
    {
        config()->set('promo.commission.fixed_per_closed_deal', '1000');

        $promo = $this->closedPromo();
        $this->service()->refund($this->admin, $promo->fresh(), 'Возврат товара');

        $this->assertSame(1, PromoCodeEvent::query()->where('action', PromoAction::CommissionAccrued->value)->count());
        $this->assertSame(1, PromoCodeEvent::query()->where('action', PromoAction::CommissionReversed->value)->count());
    }

    public function test_history_shown_to_a_partner_hides_access_traces(): void
    {
        $promo = $this->promoWithReportedDeal();

        $history = $this->actingAs($this->partner, 'api')
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7'])
            ->getJson("/promo/partner/codes/{$promo->getKey()}")
            ->assertOk()
            ->json('history');

        $this->assertNotEmpty($history);

        foreach ($history as $row) {
            $this->assertArrayNotHasKey('ip_address', $row);
            $this->assertArrayNotHasKey('user_agent', $row);
            $this->assertArrayNotHasKey('actor_id', $row);
        }
    }
}
