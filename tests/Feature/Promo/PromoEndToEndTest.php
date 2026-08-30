<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Enums\PromoCodeStatus;
use App\Models\Conversion;
use App\Models\CuratorCommission;
use App\Models\PromoCode;
use App\Models\PromoCodeEvent;
use App\Models\UserNotification;

/**
 * Основной пользовательский сценарий целиком через HTTP.
 *
 * Не через сервис: маршруты, права и валидация — часть проверяемого, и путь,
 * пройденный в обход контроллеров, не доказал бы, что он проходим снаружи.
 */
class PromoEndToEndTest extends PromoAccessTestBase
{
    public function test_full_journey_from_ad_click_to_a_paid_curator_deal(): void
    {
        config()->set('promo.commission.fixed_per_closed_deal', '2000');

        // 1. Клиент пришёл по рекламе и зарегистрировался — leget-main отдаёт
        //    накопленную в cookie атрибуцию под его токеном.
        $this->actingAs($this->client, 'api')
            ->postJson('/promo/attribution', [
                'visitor_id' => 'visitor-e2e',
                'first' => [
                    'yclid' => '7654321',
                    'utm_source' => 'yandex-direct',
                    'utm_campaign' => 'kuhni-msk',
                    'campaign_id' => '111',
                    'ad_group_id' => '222',
                    'ad_id' => '333',
                    'keyword_id' => '444',
                    'landing_url' => 'https://example.test/kuhni?yclid=7654321',
                    'touched_at' => now()->subDays(2)->toIso8601String(),
                ],
            ])
            ->assertCreated();

        // 2. Куратор находит его в очереди новых клиентов.
        $queue = $this->actingAs($this->curator, 'api')->getJson('/promo/curator/queue')->assertOk();
        $this->assertContains($this->client->email, array_column($queue->json('clients.data'), 'email'));

        // 3. Куратор создаёт промокод и активирует его.
        $created = $this->actingAs($this->curator, 'api')
            ->postJson('/promo/curator/codes', [
                'client_id' => $this->client->id,
                'partner_id' => $this->partner->id,
                'subject_type' => 'category',
                'subject_title' => 'Кухни на заказ',
                'discount_type' => 'percent',
                'discount_value' => '10',
                'terms' => 'Скидка на кухни при заказе от 50 000 ₽',
                'minimum_order_amount' => '50000',
            ])
            ->assertCreated();

        $id = $created->json('promo_code.id');
        $code = $created->json('promo_code.code');

        // До активации партнёр кода не видит.
        $this->actingAs($this->partner, 'api')
            ->getJson('/promo/partner/codes')
            ->assertJsonPath('promo_codes.total', 0);

        $this->actingAs($this->curator, 'api')
            ->postJson("/promo/curator/codes/{$id}/activate")
            ->assertOk()
            ->assertJsonPath('promo_code.status', PromoCodeStatus::Activated->value);

        // 4. Клиент видит код и условия у себя.
        $this->actingAs($this->client, 'api')
            ->getJson('/promo/client/codes')
            ->assertOk()
            ->assertJsonPath('promo_codes.total', 1)
            ->assertJsonPath('promo_codes.data.0.code', $code);

        // 5. Партнёр находит код поиском, отмечает предъявление и заказ.
        $this->actingAs($this->partner, 'api')
            ->getJson('/promo/partner/codes?code='.substr($code, 3, 4))
            ->assertOk()
            ->assertJsonPath('promo_codes.total', 1);

        $this->actingAs($this->partner, 'api')
            ->postJson("/promo/partner/codes/{$id}/present")
            ->assertOk()
            ->assertJsonPath('promo_code.status', PromoCodeStatus::Presented->value);

        $this->actingAs($this->partner, 'api')
            ->postJson("/promo/partner/codes/{$id}/order")
            ->assertOk()
            ->assertJsonPath('promo_code.status', PromoCodeStatus::OrderCreated->value);

        // 6. Партнёр заявляет сделку. Итог считает сервер.
        $this->actingAs($this->partner, 'api')
            ->postJson("/promo/partner/codes/{$id}/deal", [
                'order_number' => 'ДГ-1024',
                'deal_date' => now()->toDateString(),
                'gross_amount' => '120000.00',
                'discount_amount' => '12000.00',
                'category' => 'Кухни',
            ])
            ->assertCreated();

        $promo = PromoCode::query()->findOrFail($id);
        $this->assertSame(PromoCodeStatus::DealReported, $promo->status);
        $this->assertSame(0, bccomp('108000.00', (string) $promo->deal->net_amount, 2));

        // Вознаграждения ещё нет: сделка только заявлена.
        $this->assertSame(0, CuratorCommission::query()->count());

        // 7. Клиент получил уведомление и подтверждает сделку.
        $this->actingAs($this->client, 'api')
            ->getJson('/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('unread', 1);

        $this->actingAs($this->client, 'api')
            ->postJson("/promo/client/codes/{$id}/respond", ['response' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('promo_code.status', PromoCodeStatus::ClientConfirmed->value);

        // Подтверждение клиентом тоже ещё не начисление: закрывает платформа.
        $this->assertSame(0, CuratorCommission::query()->count());

        // Куратор закрыть не может — маршрут закрыт способностью.
        $this->actingAs($this->curator, 'api')
            ->postJson("/admin/promo-codes/{$id}/close")
            ->assertForbidden();

        // 8. Платформа закрывает сделку.
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/promo-codes/{$id}/close")
            ->assertOk()
            ->assertJsonPath('promo_code.status', PromoCodeStatus::Closed->value);

        // 9. Начислено ровно одно вознаграждение по снятому снимку правила.
        $commission = CuratorCommission::query()->firstOrFail();
        $this->assertSame(1, CuratorCommission::query()->count());
        $this->assertSame(0, bccomp('2000.00', (string) $commission->amount, 2));
        $this->assertSame($this->curator->id, (int) $commission->curator_id);

        // 10. Сделка попала в реестр конверсий для передачи в Яндекс.
        $conversion = Conversion::query()->where('type', Conversion::TYPE_PROMO_DEAL)->firstOrFail();
        $this->assertSame('7654321', $conversion->ad_id);

        // 11. Отчёт связывает сделку с рекламой — только для администратора.
        $sources = $this->actingAs($this->admin, 'api')
            ->getJson('/admin/promo-codes/reports/sources')
            ->assertOk();

        $this->assertSame('yandex-direct', $sources->json('rows.data.0.source'));
        $this->assertSame('444', $sources->json('rows.data.0.keyword_id'));

        // 12. Журнал содержит весь путь.
        $actions = PromoCodeEvent::query()
            ->where('promo_code_id', $id)
            ->get()
            ->map(static fn (PromoCodeEvent $e): string => $e->action->value)
            ->all();

        foreach ([
            'promo.created', 'promo.activated', 'promo.presented', 'promo.order_created',
            'promo.deal_reported', 'promo.client_confirmed', 'promo.closed', 'promo.commission_accrued',
        ] as $expected) {
            $this->assertContains($expected, $actions, "В журнале нет {$expected}.");
        }

        // 13. Уведомления о закрытии получили все три участника.
        $this->assertSame(
            3,
            UserNotification::query()->where('type', 'promo.deal_closed')->count(),
        );
    }

    /**
     * Второй сценарий: куратор вносит сделку за партнёра, клиент оспаривает,
     * администратор разбирает спор и подтверждает с основанием.
     */
    public function test_curator_reports_on_behalf_client_disputes_admin_resolves(): void
    {
        $promo = $this->makePromo();

        $this->actingAs($this->curator, 'api')->postJson("/promo/curator/codes/{$promo->getKey()}/activate")
            ->assertOk();

        // Без основания сервер откажет — даже если форма его не спросила.
        $this->actingAs($this->curator, 'api')
            ->postJson("/promo/curator/codes/{$promo->getKey()}/deal", [
                'order_number' => 'ДГ-2048',
                'deal_date' => now()->toDateString(),
                'gross_amount' => '80000',
                'discount_amount' => '8000',
                'on_behalf_of_partner' => true,
            ])
            ->assertStatus(422);

        $this->actingAs($this->curator, 'api')
            ->postJson("/promo/curator/codes/{$promo->getKey()}/deal", [
                'order_number' => 'ДГ-2048',
                'deal_date' => now()->toDateString(),
                'gross_amount' => '80000',
                'discount_amount' => '8000',
                'on_behalf_of_partner' => true,
                'behalf_reason' => 'Партнёр продиктовал данные по телефону',
            ])
            ->assertCreated();

        // Клиент видит, что данные внёс куратор, и оспаривает.
        $card = $this->actingAs($this->client, 'api')
            ->getJson("/promo/client/codes/{$promo->getKey()}")
            ->assertOk();

        $this->assertTrue($card->json('promo_code.deal.on_behalf_of_partner'));

        $this->actingAs($this->client, 'api')
            ->postJson("/promo/client/codes/{$promo->getKey()}/respond", [
                'response' => 'discount_missing',
                'comment' => 'Скидку в салоне не применили',
            ])
            ->assertOk()
            ->assertJsonPath('promo_code.status', PromoCodeStatus::Disputed->value);

        // Куратор не может разрешить спор в свою пользу.
        $this->actingAs($this->curator, 'api')
            ->postJson("/admin/promo-codes/{$promo->getKey()}/confirm", ['reason' => 'Всё было'])
            ->assertForbidden();

        // Администратор — может, но только с основанием.
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/promo-codes/{$promo->getKey()}/confirm", ['reason' => ''])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/promo-codes/{$promo->getKey()}/confirm", [
                'reason' => 'Партнёр прислал чек со скидкой, клиент согласился',
            ])
            ->assertOk()
            ->assertJsonPath('promo_code.status', PromoCodeStatus::ClientConfirmed->value);

        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/promo-codes/{$promo->getKey()}/close")
            ->assertOk();

        // 14. Возврат после закрытия создаёт корректировку, а не стирает историю.
        $this->actingAs($this->admin, 'api')
            ->postJson("/admin/promo-codes/{$promo->getKey()}/refund", ['reason' => 'Клиент вернул кухню'])
            ->assertOk()
            ->assertJsonPath('promo_code.status', PromoCodeStatus::Refunded->value);

        $this->assertSame(2, CuratorCommission::query()->count());
        $this->assertSame(
            1,
            CuratorCommission::query()->where('entry_type', 'reversal')->count(),
        );
    }
}
