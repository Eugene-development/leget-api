<?php

namespace App\Console\Commands;

use App\Models\License;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RestoreDeletedLicense extends Command
{
    protected $signature = 'licenses:restore-deleted {id : ID лицензии из подтверждённого обращения владельца}';

    protected $description = 'Поддержка: вернуть мягко удалённую лицензию в замороженном состоянии';

    public function handle(): int
    {
        $restored = DB::transaction(function () {
            $license = License::onlyTrashed()->whereKey($this->argument('id'))->lockForUpdate()->first();
            if (! $license) {
                return false;
            }
            $license->status = 'cancelled';
            $license->is_active = false;
            $license->restore();

            return true;
        }, 3);

        if (! $restored) {
            $this->error('Удалённая лицензия не найдена. Никаких изменений не внесено.');

            return self::FAILURE;
        }
        $this->info('Лицензия возвращена в список в замороженном состоянии. Списания остановлены. Владелец может разморозить её в настройках.');

        return self::SUCCESS;
    }
}
