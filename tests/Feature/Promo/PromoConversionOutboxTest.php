<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Events\PromoDealClosed;
use App\Models\Conversion;
use App\Support\YandexConversionExport;

/**
 * Точка передачи офлайн-конверсий.
 *
 * Закрытая сделка ложится в существующий реестр `conversions` — тот самый,
 * что уже выгружается CSV-файлом для Центра конверсий Яндекса. Реальной
 * отправки нет: нет ни credentials, ни согласованного API, и выдумывать
 * запрос к чужому сервису здесь нечего.
 */
class PromoConversionOutboxTest extends PromoAccessTestBase
{
    public function test_closing_a_deal_records_an_offline_conversion(): void
    {
        $this->attributedClient();

        $this->closedPromo();

        $conversion = Conversion::query()->where('type', Conversion::TYPE_PROMO_DEAL)->firstOrFail();

        $this->assertSame(Conversion::CHANNEL_OFFLINE, $conversion->channel);
        $this->assertSame('79991112233', $conversion->contact);
        // yclid берётся из атрибуции клиента, а не из тела запроса.
        $this->assertSame('7654321', $conversion->ad_id);
        $this->assertNotNull($conversion->promo_code_deal_id);
    }

    /** Повторное закрытие не создаёт вторую конверсию. */
    public function test_conversion_is_created_once(): void
    {
        $this->attributedClient();
        $promo = $this->closedPromo();

        $this->service()->close($this->admin, $promo->fresh());
        event(new PromoDealClosed($promo->fresh()->deal));

        $this->assertSame(1, Conversion::query()->where('type', Conversion::TYPE_PROMO_DEAL)->count());
    }

    /** Клиент без телефона и почты в выгрузку не годится — строки не будет. */
    public function test_client_without_contacts_produces_no_conversion(): void
    {
        $this->client->forceFill(['phone' => null, 'email' => 'нельзя@'])->save();

        $this->closedPromo();

        $this->assertSame(0, Conversion::query()->where('type', Conversion::TYPE_PROMO_DEAL)->count());
    }

    /** Строка выгружается в CSV, совместимом с Центром конверсий. */
    public function test_conversion_appears_in_the_yandex_csv(): void
    {
        $this->attributedClient();
        $this->closedPromo();

        $conversion = Conversion::query()->where('type', Conversion::TYPE_PROMO_DEAL)->firstOrFail();
        $row = app(YandexConversionExport::class)->row($conversion);

        $this->assertNotNull($row);
        $this->assertSame('79991112233', $row[3]);
        // Закрытая сделка — подтверждённая покупка, а не «в работе».
        $this->assertSame('PAID', $row[4]);
    }

    /** Ручной ввод офлайн-конверсии от этого не изменился. */
    public function test_manual_offline_conversion_still_exports_as_in_progress(): void
    {
        $conversion = Conversion::query()->create([
            'channel' => Conversion::CHANNEL_OFFLINE,
            'type' => 'offline_call',
            'name' => 'Анна',
            'contact' => '79991234567',
        ]);

        $row = app(YandexConversionExport::class)->row($conversion);

        $this->assertNotNull($row);
        $this->assertSame('IN_PROGRESS', $row[4]);
    }

    /** Отключаемая точка: без флага строка не пишется. */
    public function test_outbox_can_be_switched_off(): void
    {
        config()->set('promo.offline_conversions.enabled', false);

        $this->attributedClient();
        $this->closedPromo();

        $this->assertSame(0, Conversion::query()->where('type', Conversion::TYPE_PROMO_DEAL)->count());
    }
}
