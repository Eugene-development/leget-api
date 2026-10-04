<?php

namespace App\Console\Commands;

use App\Services\Growth\OfflineConversionService;
use Illuminate\Console\Command;

final class GrowthMetrika extends Command
{
    protected $signature = 'growth:metrika {--site=} {--collect-only : Only queue, do not send to Yandex}';

    protected $description = 'Поставить подтверждённые конверсии в очередь и проверить доставку в Метрику';

    public function handle(OfflineConversionService $service): int
    {
        $site = $this->option('site');
        $this->info('Создано конверсий: '.$service->collect($site));
        if (! $this->option('collect-only')) {
            $this->info('Обработано доставок: '.$service->deliver($site));
        }

        return self::SUCCESS;
    }
}
