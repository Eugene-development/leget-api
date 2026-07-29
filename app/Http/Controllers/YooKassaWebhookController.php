<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\YooKassaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Приём HTTP-уведомлений от ЮKassa.
 *
 * Уведомления не подписываются, поэтому подлинность проверяется двумя
 * способами сразу: по IP-адресу отправителя и повторным запросом состояния
 * платежа в API (тело уведомления как источник истины не используется).
 *
 * @see https://yookassa.ru/developers/using-api/webhooks
 */
class YooKassaWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly YooKassaService $yooKassa,
    ) {}

    /**
     * URL: POST /webhooks/yookassa (без аутентификации и CSRF)
     *
     * ЮKassa повторяет доставку 24 часа, пока не получит 200, поэтому на
     * «чужие» и непонятные уведомления отвечаем 200 — повторять их бессмысленно.
     */
    public function handle(Request $request): JsonResponse
    {
        if (! $this->yooKassa->isTrustedNotificationIp($request->ip())) {
            Log::warning('YooKassa webhook: уведомление с недоверенного IP', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'forbidden'], 403);
        }

        $event    = (string) $request->input('event', '');
        $objectId = (string) $request->input('object.id', '');

        if ($objectId === '') {
            Log::warning('YooKassa webhook: уведомление без идентификатора объекта', [
                'event' => $event,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        // Уведомления о возвратах и прочих объектах обрабатываются отдельно
        if (! str_starts_with($event, 'payment.')) {
            return response()->json(['status' => 'ignored']);
        }

        $payment = Payment::where('provider_payment_id', $objectId)->first();

        if (! $payment) {
            Log::warning('YooKassa webhook: платёж не найден локально', [
                'provider_payment_id' => $objectId,
                'event'               => $event,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        try {
            // Состояние берём из API, а не из тела уведомления
            $this->payments->sync($payment);
        } catch (Throwable $e) {
            Log::error('YooKassa webhook: ошибка обработки', [
                'payment_id' => $payment->id,
                'event'      => $event,
                'message'    => $e->getMessage(),
            ]);

            // 500 — ЮKassa повторит доставку позже
            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'ok']);
    }
}
