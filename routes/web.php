<?php

use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\YooKassaWebhookController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    return "Health check";
});

Route::get('/test-db', function () {
    try {
        DB::connection()->getPdo();
        return 'База данных подключена!!!';
    } catch (\Exception $e) {
        return 'Unable to connect to the database: ' . $e->getMessage();
    }
});

/*
|--------------------------------------------------------------------------
| Скачивание счёта на оплату
|--------------------------------------------------------------------------
| Требует JWT-аутентификации (middleware 'auth:api').
| Возвращает HTML-счёт, который можно распечатать / сохранить как PDF.
*/
Route::middleware('auth:api')->group(function () {
    Route::get('/invoices/{id}/download', [InvoiceController::class, 'download'])
        ->where('id', '[0-9]+')
        ->name('invoices.download');

    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'pdf'])
        ->where('id', '[0-9]+')
        ->name('invoices.pdf');
});

/*
|--------------------------------------------------------------------------
| HTTP-уведомления ЮKassa
|--------------------------------------------------------------------------
| Без аутентификации и без CSRF (исключение задано в bootstrap/app.php).
| Подлинность проверяется по IP отправителя и повторным запросом состояния
| платежа в API ЮKassa. URL для личного кабинета ЮKassa:
| https://api.leget.ru/webhooks/yookassa
*/
Route::post('/webhooks/yookassa', [YooKassaWebhookController::class, 'handle'])
    ->name('webhooks.yookassa');
