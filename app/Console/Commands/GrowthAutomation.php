<?php

namespace App\Console\Commands;

use App\Services\Growth\AutomationService;
use Illuminate\Console\Command;

final class GrowthAutomation extends Command
{
    protected $signature = 'growth:automation {--site= : Restrict to one license}';

    protected $description = 'Создать задачи и уведомления по правилам CRM';

    public function handle(AutomationService $service): int
    {
        $this->info('Выполнено правил: '.$service->run($this->option('site')));

        return self::SUCCESS;
    }
}
