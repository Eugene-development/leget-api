<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ежедневное списание средств — команда зарегистрирована в bootstrap/app.php с точным временем (07:00 МСК)
// Schedule::command('app:daily-billing')->daily(); // дублирование удалено
