<?php

use App\Http\Controllers\AdminConversionController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\YooKassaWebhookController;
use App\Http\Middleware\EnsureAdminAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    return 'Health check';
});

Route::get('/test-db', function () {
    try {
        DB::connection()->getPdo();

        return 'База данных подключена!!!';
    } catch (Exception $e) {
        return 'Unable to connect to the database: '.$e->getMessage();
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
| Администрирование конверсий
|--------------------------------------------------------------------------
|
| Доступ проверяется дважды: JWT валидируется общим guard, а allowlist
| LEGET_ADMIN_EMAILS — отдельным middleware. Эти endpoint'ы вызывает только
| серверная часть leget-main, токен не выдаётся браузеру.
*/
Route::prefix('admin')
    ->middleware(['auth:api', EnsureAdminAccess::class, 'throttle:120,1'])
    ->group(function (): void {
        Route::get('/conversions', [AdminConversionController::class, 'index']);
        Route::post('/conversions', [AdminConversionController::class, 'storeOffline'])
            ->middleware('throttle:30,1');
        Route::get('/conversions/offline/export', [AdminConversionController::class, 'exportOffline'])
            ->middleware('throttle:20,1');
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
