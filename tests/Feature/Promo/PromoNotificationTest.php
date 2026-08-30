<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Enums\PromoClientResponse;
use App\Models\UserNotification;
use App\Services\PromoNotifier;

/**
 * Уведомления кабинета.
 *
 * Внешняя отправка не имитируется: платформа умеет только положить строку
 * в БД и показать её в кабинете. Подключение почты будет вторым слушателем
 * тех же доменных событий.
 */
class PromoNotificationTest extends PromoTestCase
{
    public function test_reported_deal_notifies_the_client_and_nobody_else(): void
    {
        $this->promoWithReportedDeal();

        $notifications = UserNotification::query()
            ->where('type', PromoNotifier::DEAL_REPORTED)
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertSame($this->client->id, (int) $notifications->first()->user_id);
        $this->assertStringContainsString('Подтвердите', $notifications->first()->title);
    }

    public function test_dispute_notifies_the_curator_and_the_administrators(): void
    {
        $promo = $this->promoWithReportedDeal();

        $this->service()->respondAsClient($this->client, $promo, PromoClientResponse::NoDeal);

        $recipients = UserNotification::query()
            ->where('type', PromoNotifier::DEAL_DISPUTED)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains($this->curator->id, $recipients);
        $this->assertContains($this->admin->id, $recipients);
        $this->assertNotContains($this->client->id, $recipients);
    }

    public function test_closing_notifies_all_three_participants(): void
    {
        $this->closedPromo();

        $recipients = UserNotification::query()
            ->where('type', PromoNotifier::DEAL_CLOSED)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains($this->client->id, $recipients);
        $this->assertContains($this->partner->id, $recipients);
        $this->assertContains($this->curator->id, $recipients);
    }

    /** Уведомление уходит партнёру — рекламных данных в нём быть не должно. */
    public function test_notifications_never_carry_advertising_data(): void
    {
        $this->closedPromo();

        foreach (UserNotification::query()->get() as $notification) {
            $text = $notification->title.' '.$notification->body.' '.json_encode($notification->payload);

            $this->assertStringNotContainsString('utm', strtolower($text));
            $this->assertStringNotContainsString('yclid', strtolower($text));
        }
    }

    public function test_user_sees_only_their_own_notifications(): void
    {
        $this->promoWithReportedDeal();

        $mine = $this->actingAs($this->client, 'api')->getJson('/notifications')->assertOk();
        $this->assertSame(1, $mine->json('notifications.total'));

        $partners = $this->actingAs($this->partner, 'api')->getJson('/notifications')->assertOk();
        $this->assertSame(0, $partners->json('notifications.total'));
    }

    public function test_marking_read_is_idempotent_and_scoped(): void
    {
        $this->promoWithReportedDeal();
        $notification = UserNotification::query()->firstOrFail();

        $this->actingAs($this->client, 'api')
            ->postJson("/notifications/{$notification->getKey()}/read")
            ->assertOk();

        $readAt = $notification->fresh()->read_at;
        $this->assertNotNull($readAt);

        $this->travel(1)->minute();
        $this->actingAs($this->client, 'api')
            ->postJson("/notifications/{$notification->getKey()}/read")
            ->assertOk();

        $this->assertEquals($readAt, $notification->fresh()->read_at);

        // Чужое уведомление недостижимо.
        $this->actingAs($this->partner, 'api')
            ->postJson("/notifications/{$notification->getKey()}/read")
            ->assertNotFound();
    }
}
