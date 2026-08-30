<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Enums\PartnerType;
use App\Enums\PromoCodeStatus;
use App\Enums\Role;
use App\Models\PromoCode;

/**
 * Границы ролей на сервере.
 *
 * Проверяется не «кнопка спрятана», а что запрос не проходит: каждый маршрут
 * закрыт способностью, а выборка сужена до своего владельца.
 */
class PromoAccessTest extends PromoAccessTestBase
{
    public function test_client_sees_only_their_own_promo_codes(): void
    {
        $mine = $this->makePromo();
        $this->service()->activate($this->curator, $mine);

        $stranger = $this->promoUser('stranger@example.test');
        $theirs = $this->makePromo(['client' => $stranger]);
        $this->service()->activate($this->curator, $theirs);

        $response = $this->actingAs($this->client, 'api')->getJson('/promo/client/codes')->assertOk();

        $this->assertSame(1, $response->json('promo_codes.total'));
        $this->assertSame($mine->getKey(), $response->json('promo_codes.data.0.id'));
    }

    /** Существование чужого промокода — тоже сведение, поэтому 404, а не 403. */
    public function test_client_cannot_open_someone_elses_promo_code(): void
    {
        $stranger = $this->promoUser('stranger@example.test');
        $theirs = $this->makePromo(['client' => $stranger]);

        $this->actingAs($this->client, 'api')
            ->getJson("/promo/client/codes/{$theirs->getKey()}")
            ->assertNotFound();
    }

    public function test_client_cannot_confirm_someone_elses_deal(): void
    {
        $stranger = $this->promoUser('stranger@example.test');
        $theirs = $this->makePromo(['client' => $stranger]);
        $theirs = $this->promoWithReportedDeal($theirs);

        $this->actingAs($this->client, 'api')
            ->postJson("/promo/client/codes/{$theirs->getKey()}/respond", ['response' => 'confirmed'])
            ->assertNotFound();

        $this->assertSame(PromoCodeStatus::DealReported, $theirs->fresh()->status);
    }

    public function test_partner_sees_only_codes_assigned_to_their_organisation(): void
    {
        $mine = $this->makePromo();
        $this->service()->activate($this->curator, $mine);

        $other = $this->promoUser('other-partner@example.test', Role::Partner);
        $theirs = $this->makePromo(['partner' => $other]);
        $this->service()->activate($this->curator, $theirs);

        $response = $this->actingAs($this->partner, 'api')->getJson('/promo/partner/codes')->assertOk();

        $this->assertSame(1, $response->json('promo_codes.total'));
        $this->assertSame($mine->getKey(), $response->json('promo_codes.data.0.id'));

        $this->actingAs($this->partner, 'api')
            ->getJson("/promo/partner/codes/{$theirs->getKey()}")
            ->assertNotFound();
    }

    /** До активации код — черновик куратора, партнёру он не виден. */
    public function test_partner_does_not_see_a_code_before_activation(): void
    {
        $this->makePromo();

        $this->actingAs($this->partner, 'api')
            ->getJson('/promo/partner/codes')
            ->assertOk()
            ->assertJsonPath('promo_codes.total', 0);
    }

    /**
     * Рекламная аналитика не покидает административную выдачу — ни в списке,
     * ни в карточке, ни в каком-либо вложенном объекте.
     */
    public function test_partner_never_receives_utm_or_internal_client_identifiers(): void
    {
        $this->attributedClient();
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        $list = $this->actingAs($this->partner, 'api')->getJson('/promo/partner/codes')->assertOk();
        $card = $this->actingAs($this->partner, 'api')
            ->getJson("/promo/partner/codes/{$promo->getKey()}")
            ->assertOk();

        foreach ([$list, $card] as $response) {
            $body = $response->getContent();

            $this->assertStringNotContainsString('utm_', $body);
            $this->assertStringNotContainsString('yclid', $body);
            $this->assertStringNotContainsString('7654321', $body, 'yclid просочился партнёру.');
            $this->assertStringNotContainsString('yandex-direct', $body);
            $this->assertStringNotContainsString('attribution', $body);
        }

        // Клиент показан в пределах разрешённого: имя и телефон, без email и id.
        $this->assertSame('Client', $card->json('promo_code.client.name'));
        $this->assertNull($card->json('promo_code.client.email'));
        $this->assertNull($card->json('promo_code.client.id'));
    }

    public function test_client_never_receives_advertising_analytics(): void
    {
        $this->attributedClient();
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        $body = $this->actingAs($this->client, 'api')
            ->getJson("/promo/client/codes/{$promo->getKey()}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('utm_', $body);
        $this->assertStringNotContainsString('yclid', $body);
        $this->assertStringNotContainsString('7654321', $body);
    }

    public function test_curator_activates_the_promo_code_assigned_to_them(): void
    {
        $promo = $this->makePromo();

        $this->actingAs($this->curator, 'api')
            ->postJson("/promo/curator/codes/{$promo->getKey()}/activate")
            ->assertOk()
            ->assertJsonPath('promo_code.status', PromoCodeStatus::Activated->value);

        $this->assertSame($this->curator->id, $promo->fresh()->activated_by);
    }

    public function test_curator_works_only_with_their_own_promo_codes(): void
    {
        $other = $this->promoUser('curator2@example.test', Role::Curator);
        $theirs = $this->makePromo(['curator' => $other, 'actor' => $other]);

        $this->actingAs($this->curator, 'api')
            ->postJson("/promo/curator/codes/{$theirs->getKey()}/activate")
            ->assertNotFound();
    }

    /**
     * Ключевое: маршрутов подтверждения и закрытия в группе куратора нет,
     * а административные закрыты способностью `promo.confirm`, которой
     * у роли нет. Спрятанной кнопкой это не обойти.
     */
    public function test_curator_cannot_confirm_or_close_a_deal_over_http(): void
    {
        $promo = $this->promoWithReportedDeal();

        $this->actingAs($this->curator, 'api')
            ->postJson("/admin/promo-codes/{$promo->getKey()}/confirm", ['reason' => 'Я сам подтверждаю'])
            ->assertForbidden();

        $this->actingAs($this->curator, 'api')
            ->postJson("/admin/promo-codes/{$promo->getKey()}/close")
            ->assertForbidden();

        $this->assertSame(PromoCodeStatus::DealReported, $promo->fresh()->status);
    }

    public function test_partner_cannot_confirm_their_own_deal(): void
    {
        $promo = $this->promoWithReportedDeal();

        $this->actingAs($this->partner, 'api')
            ->postJson("/admin/promo-codes/{$promo->getKey()}/confirm", ['reason' => 'Всё верно'])
            ->assertForbidden();

        $this->actingAs($this->partner, 'api')
            ->postJson("/admin/promo-codes/{$promo->getKey()}/close")
            ->assertForbidden();
    }

    public function test_client_cannot_reach_partner_or_curator_endpoints(): void
    {
        $this->actingAs($this->client, 'api')->getJson('/promo/partner/codes')->assertForbidden();
        $this->actingAs($this->client, 'api')->getJson('/promo/curator/codes')->assertForbidden();
        $this->actingAs($this->client, 'api')->getJson('/admin/promo-codes')->assertForbidden();
    }

    public function test_partner_cannot_reach_curator_or_admin_endpoints(): void
    {
        $this->actingAs($this->partner, 'api')->getJson('/promo/curator/codes')->assertForbidden();
        $this->actingAs($this->partner, 'api')->getJson('/promo/client/codes')->assertForbidden();
        $this->actingAs($this->partner, 'api')->getJson('/admin/promo-codes')->assertForbidden();
    }

    public function test_curator_cannot_reach_the_admin_registry(): void
    {
        $this->actingAs($this->curator, 'api')->getJson('/admin/promo-codes')->assertForbidden();
        $this->actingAs($this->curator, 'api')->getJson('/admin/promo-codes/reports/sources')->assertForbidden();
    }

    public function test_guest_is_rejected_everywhere(): void
    {
        $this->getJson('/promo/client/codes')->assertUnauthorized();
        $this->getJson('/promo/partner/codes')->assertUnauthorized();
        $this->getJson('/promo/curator/codes')->assertUnauthorized();
        $this->getJson('/admin/promo-codes')->assertUnauthorized();
    }

    /**
     * Подмена идентификаторов в теле запроса.
     *
     * `curator_id` и `partner_id` промокода не читаются из тела при создании
     * сделки, а `client_id` — только как явное поле создания, которое проверяет
     * роль. Приписать сделку другому куратору нечем.
     */
    public function test_body_cannot_override_curator_or_partner_of_a_promo_code(): void
    {
        $otherCurator = $this->promoUser('curator2@example.test', Role::Curator);
        $otherPartner = $this->promoUser('partner2@example.test', Role::Partner);

        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        $this->actingAs($this->partner, 'api')
            ->postJson("/promo/partner/codes/{$promo->getKey()}/deal", $this->dealPayload([
                'curator_id' => $otherCurator->id,
                'partner_id' => $otherPartner->id,
                'client_id' => 99999,
                'status' => 'closed',
                'net_amount' => '1.00',
            ]))
            ->assertCreated();

        $promo = $promo->fresh();

        $this->assertSame($this->curator->id, $promo->curator_id);
        $this->assertSame($this->partner->id, $promo->partner_id);
        $this->assertSame($this->client->id, $promo->client_id);
        $this->assertSame(PromoCodeStatus::DealReported, $promo->status);
        // Сравнение численное: sqlite в тестах отдаёт decimal без хвостовых
        // нулей ('90000'), MySQL в проде — '90000.00'. Значение одно и то же,
        // и проверять надо его, а не форматирование драйвера.
        $this->assertSame(0, bccomp('90000.00', (string) $promo->deal->net_amount, 2));
    }

    /**
     * Подстановочные знаки в поиске — текст, а не шаблон: `_` не должен
     * находить произвольный символ.
     */
    public function test_search_treats_wildcards_as_literal_text(): void
    {
        $promo = $this->makePromo();
        $this->service()->activate($this->curator, $promo);

        $this->actingAs($this->partner, 'api')
            ->getJson('/promo/partner/codes?code=_')
            ->assertOk()
            ->assertJsonPath('promo_codes.total', 0);

        $this->actingAs($this->partner, 'api')
            ->getJson('/promo/partner/codes?code='.substr($promo->fresh()->code, 0, 2))
            ->assertOk()
            ->assertJsonPath('promo_codes.total', 1);
    }

    /** Справочник партнёров отдаёт реквизиты, но не выгрузку пользователей. */
    public function test_partner_directory_is_limited_to_partners(): void
    {
        $this->partner->partnerProfile()->create([
            'partner_type' => PartnerType::Manufacturer->value,
            'company' => 'ООО Мебельщик',
            'city' => 'Москва',
        ]);

        $response = $this->actingAs($this->curator, 'api')
            ->getJson('/promo/curator/partners')
            ->assertOk();

        $partners = $response->json('partners');

        $this->assertCount(1, $partners);
        $this->assertSame('ООО Мебельщик', $partners[0]['company']);
        // Ни клиента, ни куратора, ни админа в справочнике нет.
        $this->assertStringNotContainsString($this->client->email, $response->getContent());
        $this->assertArrayNotHasKey('email', $partners[0]);
    }

    /** Куратор не может выдать промокод «клиенту», который на деле партнёр. */
    public function test_promo_code_is_issued_only_to_a_client_role(): void
    {
        $this->actingAs($this->curator, 'api')
            ->postJson('/promo/curator/codes', [
                'client_id' => $this->partner->id,
                'subject_type' => 'custom',
                'subject_title' => 'Разовое предложение',
                'discount_type' => 'percent',
                'discount_value' => '5',
            ])
            ->assertStatus(422);

        $this->assertSame(0, PromoCode::query()->count());
    }

    /** Куратор создаёт код — куратором становится он сам, а не присланный id. */
    public function test_creating_a_code_makes_the_actor_its_curator(): void
    {
        $other = $this->promoUser('curator2@example.test', Role::Curator);

        $this->actingAs($this->curator, 'api')
            ->postJson('/promo/curator/codes', [
                'client_id' => $this->client->id,
                'curator_id' => $other->id,
                'partner_id' => $this->partner->id,
                'subject_type' => 'category',
                'subject_title' => 'Кухни',
                'discount_type' => 'fixed',
                'discount_value' => '5000',
                'currency' => 'RUB',
            ])
            ->assertCreated();

        $promo = PromoCode::query()->firstOrFail();

        $this->assertSame($this->curator->id, $promo->curator_id);
        $this->assertNotSame($other->id, $promo->curator_id);
    }
}
