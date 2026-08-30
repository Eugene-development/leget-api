<?php

use App\Http\Controllers\AdminClientController;
use App\Http\Controllers\AdminConversionController;
use App\Http\Controllers\AdminPartnerController;
use App\Http\Controllers\AdminPromoController;
use App\Http\Controllers\AttributionController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PromoClientController;
use App\Http\Controllers\PromoCuratorController;
use App\Http\Controllers\PromoPartnerController;
use App\Http\Controllers\UserNotificationController;
use App\Http\Controllers\YooKassaWebhookController;
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
| Администрирование: конверсии и клиенты
|--------------------------------------------------------------------------
|
| Доступ проверяется дважды: JWT валидируется общим guard, а право на раздел —
| штатным `can:` (способности ролей объявлены в App\Enums\Role и регистрируются
| Gate'ами в AppServiceProvider). Эти endpoint'ы вызывает только серверная часть
| leget-main, токен не выдаётся браузеру.
|
| Способность, а не имя роли: если доступ к разделу получит вторая роль, строка
| добавится в enum, а маршруты останутся нетронутыми.
*/
Route::prefix('admin')
    ->middleware(['auth:api', 'throttle:120,1'])
    ->group(function (): void {
        Route::get('/conversions', [AdminConversionController::class, 'index'])
            ->middleware('can:conversions.view');
        Route::post('/conversions', [AdminConversionController::class, 'storeOffline'])
            ->middleware(['can:conversions.record', 'throttle:30,1']);
        Route::get('/conversions/offline/export', [AdminConversionController::class, 'exportOffline'])
            ->middleware(['can:conversions.view', 'throttle:20,1']);

        // Регистрации клиентов — страница «Мои клиенты» в панели leget-main.
        Route::get('/clients', [AdminClientController::class, 'index'])
            ->middleware('can:clients.view');

        // Разбор заявок на партнёрство. Одобрение — единственное штатное место,
        // где у человека появляется роль `partner`.
        Route::middleware('can:partners.review')->group(function (): void {
            Route::get('/partners', [AdminPartnerController::class, 'index']);
            Route::post('/partners/{id}/approve', [AdminPartnerController::class, 'approve']);
            Route::post('/partners/{id}/reject', [AdminPartnerController::class, 'reject']);
        });

        /*
         * Реестр промокодов и сделок.
         *
         * Единственная выдача с рекламной аналитикой (yclid, UTM) — просмотр
         * карточки пишется в журнал как `promo.sensitive_viewed`.
         *
         * Подтверждение сделки без ответа клиента вынесено под отдельную
         * способность `promo.confirm`: это не «ещё один админский маршрут»,
         * а обход второй стороны, и право на него объявлено отдельно.
         */
        Route::middleware('can:promo.admin')->group(function (): void {
            Route::get('/promo-codes', [AdminPromoController::class, 'index']);
            Route::get('/promo-codes/reports/curators', [AdminPromoController::class, 'curatorsReport']);
            Route::get('/promo-codes/reports/partners', [AdminPromoController::class, 'partnersReport']);
            Route::get('/promo-codes/reports/sources', [AdminPromoController::class, 'sourcesReport']);
            Route::get('/promo-codes/{id}', [AdminPromoController::class, 'show']);
            Route::post('/promo-codes/{id}/cancel', [AdminPromoController::class, 'cancel']);
            Route::post('/promo-codes/{id}/refund', [AdminPromoController::class, 'refund']);
        });

        Route::middleware('can:promo.confirm')->group(function (): void {
            Route::post('/promo-codes/{id}/confirm', [AdminPromoController::class, 'confirm']);
            Route::post('/promo-codes/{id}/close', [AdminPromoController::class, 'close']);
        });
    });

/*
|--------------------------------------------------------------------------
| Промокоды: сквозная аналитика рекламных обращений и офлайн-сделок
|--------------------------------------------------------------------------
|
| Права проверяются здесь, на сервере, а не скрытием кнопок во фронтенде.
| Каждая группа закрыта своей способностью из App\Enums\Role, и разделение
| проходит ровно по границе безопасности задачи:
|
|   promo.client   — клиент: свои коды, подтверждение или спор;
|   promo.partner  — партнёр: назначенные ему коды, предъявление, заявление сделки;
|   promo.curate   — куратор: создание, активация, сопровождение, внесение
|                    сведений от имени партнёра;
|   promo.admin    — администратор: реестр, споры, отчёты;
|   promo.confirm  — административное подтверждение сделки без ответа клиента.
|
| Куратор не имеет `promo.confirm` — и именно поэтому не может единолично
| превратить заявленную им сделку в подтверждённую. Это свойство держится
| картой прав, а не проверкой внутри сервиса, которую можно забыть повторить
| в следующем маршруте.
|
| Владение проверяется в контроллерах сужением выборки: чужой промокод
| не отдаётся вовсе (404), а `client_id`, `curator_id` и `partner_id` из тела
| запроса не читаются нигде.
*/
Route::prefix('promo')
    ->middleware(['auth:api', 'throttle:120,1'])
    ->group(function (): void {
        // ─── Клиент ──────────────────────────────────────────────────────────
        Route::middleware('can:promo.client')->group(function (): void {
            Route::get('/client/codes', [PromoClientController::class, 'index']);
            Route::get('/client/codes/{id}', [PromoClientController::class, 'show']);
            Route::post('/client/codes/{id}/respond', [PromoClientController::class, 'respond'])
                ->middleware('throttle:30,1');
        });

        // ─── Партнёр ─────────────────────────────────────────────────────────
        Route::middleware('can:promo.partner')->group(function (): void {
            Route::get('/partner/codes', [PromoPartnerController::class, 'index']);
            Route::get('/partner/codes/{id}', [PromoPartnerController::class, 'show']);
            Route::post('/partner/codes/{id}/present', [PromoPartnerController::class, 'present']);
            Route::post('/partner/codes/{id}/order', [PromoPartnerController::class, 'order']);
            Route::post('/partner/codes/{id}/deal', [PromoPartnerController::class, 'reportDeal'])
                ->middleware('throttle:30,1');
        });

        // ─── Куратор ─────────────────────────────────────────────────────────
        //
        // Маршрутов «подтвердить» и «закрыть» в этой группе нет намеренно.
        Route::middleware('can:promo.curate')->group(function (): void {
            Route::get('/curator/queue', [PromoCuratorController::class, 'queue']);
            Route::get('/curator/codes', [PromoCuratorController::class, 'index']);
            Route::post('/curator/codes', [PromoCuratorController::class, 'store'])
                ->middleware('throttle:60,1');
            Route::get('/curator/report', [PromoCuratorController::class, 'report']);
            Route::get('/curator/partners', [PromoCuratorController::class, 'partners']);
            Route::get('/curator/codes/{id}', [PromoCuratorController::class, 'show']);
            Route::post('/curator/codes/{id}/activate', [PromoCuratorController::class, 'activate']);
            Route::post('/curator/codes/{id}/partner', [PromoCuratorController::class, 'assignPartner']);
            Route::post('/curator/codes/{id}/terms', [PromoCuratorController::class, 'updateTerms']);
            Route::post('/curator/codes/{id}/deal', [PromoCuratorController::class, 'reportDeal'])
                ->middleware('throttle:30,1');
            Route::post('/curator/codes/{id}/cancel', [PromoCuratorController::class, 'cancel']);
        });

        /*
         * Рекламная атрибуция.
         *
         * Пишет только сам клиент про себя: `user_id` берётся из токена,
         * поля для чужого идентификатора в запросе нет. Второго входа в таблицу
         * `ad_attributions` не существует — ни у куратора, ни у партнёра,
         * ни у администратора. Исходный источник, из которого растёт
         * вознаграждение куратора, переписать нечем.
         */
        Route::post('/attribution', [AttributionController::class, 'store'])
            ->middleware(['can:promo.client', 'throttle:30,1']);
    });

/*
|--------------------------------------------------------------------------
| Уведомления кабинета
|--------------------------------------------------------------------------
| Без `can:`: уведомления получают все роли. Границей служит выборка —
| `user_id` берётся из токена.
*/
Route::prefix('notifications')
    ->middleware(['auth:api', 'throttle:120,1'])
    ->group(function (): void {
        Route::get('/', [UserNotificationController::class, 'index']);
        Route::post('/{id}/read', [UserNotificationController::class, 'markRead']);
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
