<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\License;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingService
{
    public static function firstBillingDate(Carbon $startsAt): string
    {
        $start = $startsAt->copy()->timezone('Europe/Moscow');
        $scheduled = $start->copy()->setTime(6, 30);

        return ($scheduled->lt($start) ? $scheduled->addDay() : $scheduled)->toDateString();
    }

    public function processDailyDebits(): array
    {
        $results = ['processed' => 0, 'charged' => 0, 'negative' => 0, 'errors' => 0];
        License::where('status', 'active')->where('is_active', true)
            ->whereNotNull('billing_started_at')->select('id')->orderBy('id')->chunkById(100, function ($licenses) use (&$results) {
                foreach ($licenses as $license) {
                    $results['processed']++;
                    try {
                        $charged = DB::transaction(function () use ($license) {
                            $locked = License::whereKey($license->id)->lockForUpdate()->firstOrFail();

                            return $this->settleLockedLicense($locked);
                        }, 3);
                        $results['charged'] += $charged;
                    } catch (\Throwable $e) {
                        $results['errors']++;
                        Log::error('Биллинг: ошибка списания', ['license_id' => $license->id, 'error' => $e->getMessage()]);
                    }
                }
            });
        $results['negative'] = Wallet::where('balance', '<', 0)->count();

        return $results;
    }

    /** Caller holds the license row lock in a transaction, also for cancellation/rate changes. */
    public function settleLockedLicense(License $license): int
    {
        if (! $license->is_active || $license->status !== 'active' || ! $license->billing_started_at) {
            return 0;
        }
        $now = Carbon::now('Europe/Moscow');
        $through = $now->copy()->startOfDay();
        if ($now->lt($now->copy()->setTime(6, 30))) {
            $through->subDay();
        }
        $date = Carbon::parse($license->next_billing_date ?? self::firstBillingDate($license->billing_started_at), 'Europe/Moscow')->startOfDay();
        if ($date->gt($through)) {
            return 0;
        }
        if (bccomp((string) $license->daily_price, '0', 2) < 0) {
            throw new \RuntimeException('Negative rental price');
        }
        Wallet::forUser($license->user_id);
        $wallet = Wallet::where('user_id', $license->user_id)->lockForUpdate()->firstOrFail();
        $charged = 0;
        while ($date->lte($through)) {
            $day = $date->toDateString();
            if (! Transaction::where('license_id', $license->id)->where('billing_date', $day)->exists()) {
                Transaction::create([
                    'wallet_id' => $wallet->id, 'license_id' => $license->id,
                    'billing_date' => $day, 'amount' => $license->daily_price, 'type' => 'withdraw',
                    'description' => "Аренда за {$day}: {$license->name} ({$license->domain})",
                ]);
                $wallet->balance = bcsub($wallet->balance, (string) $license->daily_price, 2);
                $charged++;
            }
            $date->addDay();
        }
        $wallet->save();
        $license->next_billing_date = $date->toDateString();
        $license->save();

        return $charged;
    }
}
