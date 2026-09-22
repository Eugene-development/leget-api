<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WalletOpeningBalance;
use Illuminate\Console\Command;

class RecordWalletOpeningBalance extends Command
{
    protected $signature = 'wallet:record-opening-balance {wallet} {amount} {--reason=} {--apply}';

    protected $description = 'Учесть подтверждённый начальный баланс в истории, сохранив текущий остаток (по умолчанию dry-run)';

    public function handle(WalletOpeningBalance $service): int
    {
        try {
            $result = $service->record((int) $this->argument('wallet'), (string) $this->argument('amount'), (string) $this->option('reason'), (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
