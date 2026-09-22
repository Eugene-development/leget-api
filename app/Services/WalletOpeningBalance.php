<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class WalletOpeningBalance
{
    /** Record a confirmed historical opening amount without changing the current balance. */
    public function record(int $walletId, string $amount, string $reason, bool $apply = false): array
    {
        if (! preg_match('/^-?\d+(\.\d{1,2})?$/D', $amount) || bccomp($amount, '0', 2) === 0 || trim($reason) === '') {
            throw new RuntimeException('Нужны ненулевая сумма с точностью до копейки и основание.');
        }

        return DB::transaction(function () use ($walletId, $amount, $reason, $apply) {
            $wallet = Wallet::whereKey($walletId)->lockForUpdate()->firstOrFail();
            $ledger = (string) (Transaction::where('wallet_id', $walletId)
                ->selectRaw("COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0) AS total")
                ->value('total') ?? '0');
            $difference = bcsub($wallet->balance, $ledger, 2);
            $description = 'Начальный баланс: '.trim($reason);
            if (mb_strlen($description) > 255) {
                throw new RuntimeException('Основание слишком длинное.');
            }
            $type = bccomp($amount, '0', 2) > 0 ? 'deposit' : 'withdraw';
            $absolute = ltrim($amount, '-');
            $existing = Transaction::where('wallet_id', $walletId)->where('description', $description)
                ->where('type', $type)->where('amount', $absolute)->first();
            if ($existing && bccomp($difference, '0', 2) === 0) {
                return ['balance' => $wallet->balance, 'difference' => $difference, 'recorded' => false, 'transaction_id' => $existing->id];
            }
            if (bccomp($difference, $amount, 2) !== 0 || $existing) {
                throw new RuntimeException("Разница {$difference} не совпадает с подтверждённой суммой {$amount}. Запись отменена.");
            }
            $transaction = $apply ? Transaction::create([
                'wallet_id' => $walletId, 'type' => $type, 'amount' => $absolute, 'description' => $description,
            ]) : null;

            return ['balance' => $wallet->balance, 'difference' => $difference, 'recorded' => $transaction !== null, 'transaction_id' => $transaction?->id];
        }, 3);
    }
}
