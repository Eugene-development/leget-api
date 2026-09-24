<?php

declare(strict_types=1);

namespace App\Services\Crm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CrmDefaults
{
    public const STAGES = [
        ['lead', 'Лид', 'open'], ['contact', 'Контакт установлен', 'open'],
        ['qualified', 'Потребность уточнена', 'open'], ['proposal', 'Предложение отправлено', 'open'],
        ['negotiation', 'Согласование договора', 'open'], ['signed', 'Договор подписан', 'signed'],
        ['execution', 'Исполнение', 'execution'], ['acceptance', 'Приёмка', 'acceptance'],
        ['won', 'Закрыто успешно', 'won'], ['lost', 'Закрыто без сделки', 'lost'],
    ];

    public function seed(string $site): void
    {
        DB::table('crm_settings')->insertOrIgnore(['license_id' => $site, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (self::STAGES as $order => [$key, $name, $kind]) {
            DB::table('crm_stages')->insertOrIgnore([
                'id' => (string) Str::ulid(), 'license_id' => $site, 'system_key' => $key, 'name' => $name,
                'kind' => $kind, 'sort_order' => $order * 10, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
