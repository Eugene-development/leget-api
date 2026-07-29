<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Тонкий клиент к API ЮKassa (Checkout API v3).
 *
 * Отвечает только за общение с провайдером: создание платежа, получение его
 * актуального состояния и проверка источника уведомлений. Бизнес-логика
 * зачисления на баланс живёт в PaymentService.
 *
 * @see https://yookassa.ru/developers/api
 */
final class YooKassaService
{
    /**
     * Настроена ли интеграция (есть секретный ключ).
     */
    public function isConfigured(): bool
    {
        return filled($this->config('shop_id')) && filled($this->config('secret_key'));
    }

    /**
     * Создаёт платёж у провайдера и возвращает его объект.
     *
     * @return array<string, mixed> Объект платежа ЮKassa
     */
    public function createPayment(Payment $payment, string $returnUrl): array
    {
        $body = [
            'amount' => [
                // ЮKassa ждёт строку с двумя знаками после точки
                'value'    => number_format((float) $payment->amount, 2, '.', ''),
                'currency' => $payment->currency ?: 'RUB',
            ],
            // true — деньги списываются сразу, без отдельного подтверждения
            'capture'      => true,
            'confirmation' => [
                'type'       => 'redirect',
                'return_url' => $returnUrl,
            ],
            'description' => mb_substr((string) $this->config('description'), 0, 128),
            'metadata'    => [
                'payment_id' => (string) $payment->id,
                'user_id'    => (string) $payment->user_id,
            ],
        ];

        $response = $this->client()
            // Ключ идемпотентности: повторный запрос с тем же ключом вернёт тот же платёж
            ->withHeaders(['Idempotence-Key' => $payment->idempotence_key])
            ->post($this->config('api_url') . '/payments', $body);

        if ($response->failed()) {
            Log::error('YooKassa: не удалось создать платёж', [
                'payment_id' => $payment->id,
                'status'     => $response->status(),
                'body'       => $response->json() ?? $response->body(),
            ]);

            throw new RuntimeException($this->errorMessage($response->json()));
        }

        return $response->json();
    }

    /**
     * Запрашивает актуальное состояние платежа у провайдера.
     *
     * @return array<string, mixed>
     */
    public function findPayment(string $providerPaymentId): array
    {
        $response = $this->client()
            ->get($this->config('api_url') . '/payments/' . $providerPaymentId);

        if ($response->failed()) {
            Log::error('YooKassa: не удалось получить платёж', [
                'provider_payment_id' => $providerPaymentId,
                'status'              => $response->status(),
                'body'                => $response->json() ?? $response->body(),
            ]);

            throw new RuntimeException($this->errorMessage($response->json()));
        }

        return $response->json();
    }

    /**
     * Пришло ли уведомление с одного из адресов ЮKassa.
     */
    public function isTrustedNotificationIp(?string $ip): bool
    {
        if ($this->config('skip_ip_check')) {
            return true;
        }

        if ($ip === null || $ip === '') {
            return false;
        }

        return IpUtils::checkIp($ip, (array) $this->config('notification_ips', []));
    }

    private function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Онлайн-оплата не настроена: не заданы YOOKASSA_SHOP_ID / YOOKASSA_SECRET_KEY.');
        }

        return Http::withBasicAuth(
            (string) $this->config('shop_id'),
            (string) $this->config('secret_key')
        )
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(20);
    }

    /**
     * Достаёт человекочитаемое описание ошибки из ответа провайдера.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function errorMessage(?array $payload): string
    {
        $description = $payload['description'] ?? null;

        return $description
            ? 'Платёжный сервис отклонил запрос: ' . $description
            : 'Платёжный сервис недоступен. Попробуйте позже.';
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config('billing.yookassa.' . $key, $default);
    }
}
