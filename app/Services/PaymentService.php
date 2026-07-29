<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $wallet = Wallet::where('user_id', $user->id)->firstOrFail();

        $payment = Payment::create([
            'user_id'         => $user->id,
            'wallet_id'       => $wallet->id,
            'provider'        => 'yookassa',
            'idempotence_key' => (string) Str::uuid(),
            'amount'          => $amount,
            'currency'        => 'RUB',
            'status'          => Payment::STATUS_PENDING,
        ]);

        try {
            $data = $this->yooKassa->createPayment($payment, $this->returnUrl($payment));
        } catch (Throwable $e) {
            // Не оставляем «висящий» pending, по которому никто никогда не заплатит
            $payment->update([
                'status'              => Payment::STATUS_CANCELED,
                'cancellation_reason' => 'provider_request_failed',
            ]);

            throw $e;
        }

        $payment->update([
            'provider_payment_id' => $data['id'] ?? null,
            'status'              => $data['status'] ?? Payment::STATUS_PENDING,
            'provider_payload'    => $data,
        ]);

        return [
            'payment'          => $payment->refresh(),
            'confirmation_url' => $data['confirmation']['confirmation_url'] ?? null,
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
        $status = (string) ($data['status'] ?? $payment->status);

        $payment->update([
            'status'              => $status,
            'provider_payload'    => $data,
            'cancellation_reason' => $data['cancellation_details']['reason'] ?? $payment->cancellation_reason,
        ]);

        if ($status === Payment::STATUS_SUCCEEDED) {
            $paidAmount = (string) ($data['amount']['value'] ?? $payment->amount);
            $this->credit($payment, $paidAmount);
        }

        return $payment->refresh();
    }

    /**
     * Зачисляет оплаченную сумму на баланс кошелька.
     *
     * Идемпотентность: платёж блокируется на чтение, и если transaction_id уже
     * заполнен, повторное уведомление ничего не делает.
     */
    private function credit(Payment $payment, string $paidAmount): void
    {
        if (bccomp($paidAmount, (string) $payment->amount, 2) !== 0) {
            Log::warning('Оплаченная сумма не совпадает с суммой платежа', [
                'payment_id'  => $payment->id,
                'expected'    => $payment->amount,
                'paid'        => $paidAmount,
            ]);
        }

        DB::transaction(function () use ($payment, $paidAmount): void {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->transaction_id !== null) {
                return; // уже зачислено другим уведомлением
            }

            $wallet = Wallet::whereKey($locked->wallet_id)->lockForUpdate()->firstOrFail();
            $wallet->balance = bcadd((string) $wallet->balance, $paidAmount, 2);
            $wallet->save();

            $transaction = Transaction::create([
                'wallet_id'   => $wallet->id,
                'amount'      => $paidAmount,
                'type'        => 'deposit',
                'description' => 'Пополнение баланса (онлайн)',
            ]);

            $locked->update([
                'status'         => Payment::STATUS_SUCCEEDED,
                'paid_at'        => $locked->paid_at ?? now(),
                'transaction_id' => $transaction->id,
            ]);

            Log::info('Баланс пополнен онлайн-платежом', [
                'payment_id'     => $locked->id,
                'wallet_id'      => $wallet->id,
                'amount'         => $paidAmount,
                'transaction_id' => $transaction->id,
            ]);
        });
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

        return rtrim($base, '/') . $separator . 'payment=' . $payment->id;
    }
}
