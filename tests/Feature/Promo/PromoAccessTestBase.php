<?php

declare(strict_types=1);

namespace Tests\Feature\Promo;

use App\Models\AdAttribution;

/**
 * База для HTTP-тестов доступа: добавляет клиенту рекламную атрибуцию,
 * чтобы было чему просачиваться туда, где её быть не должно.
 */
abstract class PromoAccessTestBase extends PromoTestCase
{
    protected function attributedClient(): AdAttribution
    {
        $attribution = new AdAttribution;

        $attribution->forceFill([
            'user_id' => $this->client->id,
            'visitor_id' => 'visitor-abc',
            'first_yclid' => '7654321',
            'first_utm_source' => 'yandex-direct',
            'first_utm_medium' => 'cpc',
            'first_utm_campaign' => 'kuhni-msk',
            'first_campaign_id' => '111',
            'first_ad_group_id' => '222',
            'first_ad_id' => '333',
            'first_keyword_id' => '444',
            'first_landing_url' => 'https://example.test/?yclid=7654321',
            'first_referrer' => 'https://yandex.ru/',
            'first_touched_at' => now()->subDays(3),
            'last_yclid' => '7654321',
            'last_utm_source' => 'yandex-direct',
            'last_touched_at' => now()->subDay(),
        ])->save();

        return $attribution;
    }
}
