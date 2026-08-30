<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Models\AdAttribution;

/**
 * Рекламная атрибуция: сохранение при регистрации и неприкосновенность
 * первого касания.
 */
class PromoAttributionTest extends PromoAccessTestBase
{
    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'visitor_id' => 'visitor-abc',
            'first' => [
                'yclid' => '7654321',
                'utm_source' => 'yandex-direct',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'kuhni-msk',
                'utm_content' => 'banner-1',
                'utm_term' => 'кухни на заказ',
                'campaign_id' => '111',
                'ad_group_id' => '222',
                'ad_id' => '333',
                'keyword_id' => '444',
                'landing_url' => 'https://example.test/kuhni?yclid=7654321',
                'referrer' => 'https://yandex.ru/',
                'touched_at' => now()->subDays(2)->toIso8601String(),
            ],
            'last' => [
                'yclid' => '999888',
                'utm_source' => 'yandex-direct',
                'utm_campaign' => 'retarget',
                'landing_url' => 'https://example.test/?yclid=999888',
                'touched_at' => now()->subHour()->toIso8601String(),
            ],
        ];
    }

    public function test_attribution_is_stored_for_the_registered_client(): void
    {
        $this->actingAs($this->client, 'api')
            ->postJson('/promo/attribution', $this->payload())
            ->assertCreated()
            ->assertJsonPath('recorded', true);

        $attribution = AdAttribution::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame('7654321', $attribution->first_yclid);
        $this->assertSame('yandex-direct', $attribution->first_utm_source);
        $this->assertSame('kuhni-msk', $attribution->first_utm_campaign);
        $this->assertSame('111', $attribution->first_campaign_id);
        $this->assertSame('444', $attribution->first_keyword_id);
        $this->assertSame('https://yandex.ru/', $attribution->first_referrer);
        $this->assertSame('999888', $attribution->last_yclid);
        $this->assertNotNull($attribution->first_touched_at);
    }

    /**
     * Первое касание пишется один раз. Из него растёт вознаграждение куратора,
     * и переписать источник не должен никто — включая самого клиента.
     */
    public function test_first_touch_is_never_overwritten(): void
    {
        $this->actingAs($this->client, 'api')->postJson('/promo/attribution', $this->payload())->assertCreated();

        $this->actingAs($this->client, 'api')->postJson('/promo/attribution', [
            'first' => ['yclid' => '000000', 'utm_source' => 'подделка'],
            'last' => ['yclid' => '555444', 'utm_source' => 'email'],
        ])->assertCreated();

        $attribution = AdAttribution::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame('7654321', $attribution->first_yclid);
        $this->assertSame('yandex-direct', $attribution->first_utm_source);
        // Последнее касание обновляется — это его работа.
        $this->assertSame('555444', $attribution->last_yclid);
    }

    /** Обычный вход без меток не должен затирать источник. */
    public function test_empty_touch_does_not_erase_the_source(): void
    {
        $this->actingAs($this->client, 'api')->postJson('/promo/attribution', $this->payload())->assertCreated();

        $this->actingAs($this->client, 'api')
            ->postJson('/promo/attribution', ['first' => [], 'last' => []])
            ->assertOk()
            ->assertJsonPath('recorded', false);

        $attribution = AdAttribution::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame('7654321', $attribution->first_yclid);
        $this->assertSame('999888', $attribution->last_yclid);
    }

    /** Пришёл не из рекламы — строки нет, а не строка из одних NULL. */
    public function test_visit_without_advertising_creates_nothing(): void
    {
        $this->actingAs($this->client, 'api')
            ->postJson('/promo/attribution', [])
            ->assertOk()
            ->assertJsonPath('recorded', false);

        $this->assertSame(0, AdAttribution::query()->count());
    }

    /** Атрибуция принадлежит тому, чей токен предъявлен. Поля для чужого id нет. */
    public function test_attribution_always_belongs_to_the_authenticated_user(): void
    {
        $stranger = $this->promoUser('stranger@example.test');

        $this->actingAs($this->client, 'api')
            ->postJson('/promo/attribution', $this->payload() + ['user_id' => $stranger->id])
            ->assertCreated();

        $this->assertSame(1, AdAttribution::query()->count());
        $this->assertSame(
            $this->client->id,
            (int) AdAttribution::query()->firstOrFail()->user_id,
        );
    }

    /**
     * Ни куратору, ни партнёру нет пути в таблицу атрибуции: способность
     * `promo.client` есть только у клиента, а второго маршрута не существует.
     */
    public function test_curator_and_partner_cannot_write_attribution(): void
    {
        $this->actingAs($this->curator, 'api')
            ->postJson('/promo/attribution', $this->payload())
            ->assertForbidden();

        $this->actingAs($this->partner, 'api')
            ->postJson('/promo/attribution', $this->payload())
            ->assertForbidden();

        $this->actingAs($this->admin, 'api')
            ->postJson('/promo/attribution', $this->payload())
            ->assertForbidden();

        $this->assertSame(0, AdAttribution::query()->count());
    }

    /** Промокод связывается с атрибуцией клиента сервером, а не запросом. */
    public function test_promo_code_links_to_the_clients_attribution(): void
    {
        $attribution = $this->attributedClient();

        $promo = $this->makePromo();

        $this->assertSame($attribution->getKey(), $promo->attribution_id);
    }

    /** Часы браузера в будущем не должны растягивать интервал воронки. */
    public function test_future_touch_time_is_clamped_to_now(): void
    {
        $this->actingAs($this->client, 'api')->postJson('/promo/attribution', [
            'first' => [
                'yclid' => '123123',
                'touched_at' => now()->addYear()->toIso8601String(),
            ],
        ])->assertCreated();

        $attribution = AdAttribution::query()->firstOrFail();

        $this->assertTrue($attribution->first_touched_at->lessThanOrEqualTo(now()->addMinute()));
    }

    /** Только администратор видит рекламные идентификаторы. */
    public function test_only_the_admin_report_exposes_yclid_and_utm(): void
    {
        $this->attributedClient();
        $promo = $this->closedPromo();

        $body = $this->actingAs($this->admin, 'api')
            ->getJson("/admin/promo-codes/{$promo->getKey()}")
            ->assertOk();

        $this->assertSame('7654321', $body->json('promo_code.attribution.first.yclid'));
        $this->assertSame('yandex-direct', $body->json('promo_code.attribution.first.utm_source'));
        $this->assertNotNull($body->json('promo_code.timings.ad_to_registration'));
    }
}
