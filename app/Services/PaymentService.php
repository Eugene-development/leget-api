<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Онлайн-пополнение баланса.
 *
 * Держит две операции: старт платежа (создание локальной записи + платежа у
 * провайдера) и синхронизацию состояния. Синхронизация — единственное место,
 * где деньги попадают на баланс, и её вызывают оба входа: webhook от ЮKassa и
 * возврат пользователя в личный кабинет. Поэтому она обязана быть
 * идемпотентной.
 */
final class PaymentService
{
    public function __construct(private readonly YooKassaService $yooKassa) {}

    /**
     * Создаёт платёж и возвращает ссылку на страницу оплаты.
     *
     * @return array{payment: Payment, confirmation_url: ?string}
     */
    public function start(User $user, string $amount): array
    {
        $wallet = Wallet::forUser($user->id);

        $payment = Payment::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'provider' => 'yookassa',
            'idempotence_key' => (string) Str::uuid(),
            'amount' => $amount,
            'currency' => 'RUB',
            'status' => Payment::STATUS_PENDING,
        ]);

        try {
            $data = $this->yooKassa->createPayment($payment, $this->returnUrl($payment));
        } catch (Throwable $e) {
            // Не оставляем «висящий» pending, по которому никто никогда не заплатит
            $payment->update([
                'status' => Payment::STATUS_CANCELED,
                'cancellation_reason' => 'provider_request_failed',
            ]);

            throw $e;
        }

        $payment->update([
            'provider_payment_id' => $data['id'] ?? null,
            'provider_payload' => $data,
        ]);

        $payment = $this->applyProviderState($payment, $data);

        return [
            'payment' => $payment->refresh(),
            'confirmation_url' => $payment->isCredited() ? $this->returnUrl($payment) : ($data['confirmation']['confirmation_url'] ?? null),
        ];
    }

    /**
     * Подтягивает состояние платежа у провайдера и, если он оплачен,
     * зачисляет деньги на баланс.
     */
    public function sync(Payment $payment): Payment
    {
        if ($payment->provider_payment_id === null) {
            return $payment;
        }

        // Уже зачислено — провайдера дёргать незачем
        if ($payment->isCredited()) {
            return $payment;
        }

        $data = $this->yooKassa->findPayment($payment->provider_payment_id);

        return $this->applyProviderState($payment, $data);
    }

    /**
     * Применяет состояние платежа, полученное от провайдера.
     *
     * @param  array<string, mixed>  $data  Объект платежа ЮKassa
     */
    public function applyProviderState(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data): Payment {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->isCredited()) {
                return $locked;
            }
            if (($data['id'] ?? null) !== $locked->provider_payment_id) {
                throw new RuntimeException('Идентификатор платежа не совпадает с ответом провайдера.');
            }
            $status = (string) ($data['status'] ?? '');
            if (! in_array($status, ['pending', 'waiting_for_capture', 'succeeded', 'canceled'], true)) {
                throw new RuntimeException('Неизвестный статус платежа.');
            }
            // A late non-terminal response cannot resurrect a finished payment.
            if (in_array($locked->status, ['canceled', 'test'], true)) {
                return $locked;
            }
            if (($data['test'] ?? false) === true) {
                $locked->update(['status' => 'test', 'provider_payload' => $data,
                    'cancellation_reason' => 'test_payment_not_credited']);

                return $locked;
            }
            if ($status === Payment::STATUS_SUCCEEDED) {
                $amount = $data['amount']['value'] ?? '';
                if (($data['paid'] ?? false) !== true
                    || ($data['amount']['currency'] ?? null) !== $locked->currency
                    || ! is_string($amount) || ! preg_match('/^\d+\.\d{2}$/D', $amount)
                    || bccomp($amount, (string) $locked->amount, 2) !== 0) {
                    throw new RuntimeException('Платёж не прошёл проверку суммы, валюты или подтверждения.');
                }
                $wallet = Wallet::whereKey($locked->wallet_id)->lockForUpdate()->firstOrFail();
                $transaction = Transaction::create([
                    'wallet_id' => $wallet->id, 'amount' => $amount, 'type' => 'deposit',
                    'description' => 'Пополнение баланса (онлайн)',
                ]);
                $wallet->update(['balance' => bcadd($wallet->balance, $amount, 2)]);
                $locked->transaction_id = $transaction->id;
                $locked->paid_at = now();
            }
            $locked->fill([
                'status' => $status, 'provider_payload' => $data,
                'cancellation_reason' => $data['cancellation_details']['reason'] ?? null,
            ])->save();

            return $locked;
        }, 3);
    }

    /**
     * URL, на который ЮKassa вернёт пользователя после оплаты.
     */
    private function returnUrl(Payment $payment): string
    {
        $base = (string) config('billing.yookassa.return_url');

        if ($base === '') {
            throw new RuntimeException('Не задан YOOKASSA_RETURN_URL — некуда возвращать пользователя после оплаты.');
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return rtrim($base, '/').$separator.'payment='.$payment->id;
    }
}
