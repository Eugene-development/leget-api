<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InvoicePaymentService
{
    public function pay(int $id): Invoice
    {
        return DB::transaction(function () use ($id) {
            $invoice = Invoice::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'paid' || $invoice->transaction_id !== null) {
                return $invoice;
            }
            if ($invoice->status !== 'pending') {
                throw new RuntimeException('Нельзя оплатить отменённый счёт.');
            }
            $wallet = Wallet::whereKey($invoice->wallet_id)->lockForUpdate()->firstOrFail();
            $transaction = Transaction::create([
                'wallet_id' => $wallet->id, 'amount' => $invoice->amount, 'type' => 'deposit',
                'description' => 'Оплата по безналичному счёту №'.$invoice->number,
            ]);
            $wallet->update(['balance' => bcadd($wallet->balance, $invoice->amount, 2)]);
            $invoice->update(['status' => 'paid', 'paid_at' => now(), 'transaction_id' => $transaction->id]);

            return $invoice;
        }, 3);
    }

    public function cancel(int $id): Invoice
    {
        return DB::transaction(function () use ($id) {
            $invoice = Invoice::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'paid' || $invoice->transaction_id !== null) {
                throw new RuntimeException('Оплаченный счёт отменять нельзя.');
            }
            $invoice->update(['status' => 'cancelled']);

            return $invoice;
        }, 3);
    }
}
