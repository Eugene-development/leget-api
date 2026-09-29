<?php

use App\Services\University\MediaStorage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ежедневное списание средств — команда зарегистрирована в bootstrap/app.php с точным временем (07:00 МСК)
// Schedule::command('app:daily-billing')->daily(); // дублирование удалено

Artisan::command('university:prune-uploads', function () {
    $storage = app(MediaStorage::class);
    DB::table('university_media')->where('status', 'uploading')
        ->where('created_at', '<', now()->subDay())->orderBy('id')->chunkById(100, function ($rows) use ($storage) {
            foreach ($rows as $row) {
                $storage->abort($row);
            }
        });
    $this->info('Незавершённые учебные загрузки очищены.');
})->purpose('Удалить незавершённые учебные загрузки старше суток');
