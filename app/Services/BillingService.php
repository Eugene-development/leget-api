<?php

namespace App\Services;

use App\Models\License;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingService
{
    /**
     * Результаты обработки ежедневного биллинга.
     *
     * @var array{processed: int, charged: int, negative: int, errors: int}
     */
    protected array $results = [
        'processed' => 0,
        'charged' => 0,
        'negative' => 0,
        'errors' => 0,
    ];

    /**
     * Обработка ежедневных списаний за аренду сайтов.
     *
     * Метод находит все активные лицензии (licenses), у которых наступил срок биллинга,
     * считывает их daily_price и списывает сумму с кошелька владельца.
     * Баланс может уходить в отрицательное значение — списание производится всегда.
     *
     * @return array{processed: int, charged: int, negative: int, errors: int}
     */
    public function processDailyDebits(): array
    {
        // Сбрасываем счётчики перед каждым запуском
        $this->results = [
            'processed' => 0,
            'charged' => 0,
            'negative' => 0,
            'errors' => 0,
        ];

        // Получаем все активные лицензии с предзагрузкой кошелька владельца.
        // Лицензии со статусом 'cancelled' исключаются — биллинг для них остановлен.
        // Биллинг начинается только после даты billing_started_at.
        $activeLicenses = License::where('status', 'active')
            ->where('is_active', true)
            ->where('billing_started_at', '<=', now())
            ->with('user.wallet')
            ->get();

        Log::info('Биллинг: начало обработки', [
            'total_active_licenses' => $activeLicenses->count(),
        ]);

        foreach ($activeLicenses as $license) {
            $this->results['processed']++;
            $this->processLicense($license);
        }

        Log::info('Биллинг: обработка завершена', $this->results);

        return $this->results;
    }

    /**
     * Обработка списания для конкретной лицензии.
     *
     * Использует Database Transaction и lockForUpdate() для безопасной
     * работы с балансом кошелька, исключая гонку данных (race condition).
     */
    protected function processLicense(License $license): void
    {
        try {
            DB::transaction(function () use ($license) {
                $user = $license->user;

                if (!$user) {
                    Log::warning('Биллинг: у лицензии отсутствует владелец', [
                        'license_id' => $license->id,
                    ]);
                    $this->results['errors']++;
                    return;
                }

                // Блокируем кошелёк для обновления (pessimistic locking)
                $wallet = Wallet::where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (!$wallet) {
                    Log::warning('Биллинг: у пользователя отсутствует кошелёк', [
                        'license_id' => $license->id,
                        'user_id' => $user->id,
                    ]);
                    $this->results['errors']++;
                    return;
                }

                $dailyPrice = $license->daily_price;

                // Списываем средства с кошелька (баланс может уйти в минус)
                $this->chargeWallet($wallet, $dailyPrice, $license);
            });
        } catch (\Throwable $e) {
            Log::error('Биллинг: ошибка при обработке лицензии', [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
            ]);
            $this->results['errors']++;
        }
    }

    /**
     * Списание средств с кошелька и создание записи транзакции.
     *
     * Баланс может уйти в отрицательное значение — списание производится всегда.
     */
    protected function chargeWallet(Wallet $wallet, string $amount, License $license): void
    {
        // Уменьшаем баланс кошелька
        $wallet->balance = bcsub($wallet->balance, $amount, 2);
        $wallet->save();

        // Создаём запись о транзакции списания
        Transaction::create([
            'wallet_id' => $wallet->id,
            'license_id' => $license->id,
            'amount' => $amount,
            'type' => 'withdraw',
            'description' => "Списание \"{$license->name}\" ({$license->domain})",
        ]);

        // Фиксируем, если баланс ушёл в минус
        if (bccomp($wallet->balance, '0', 2) < 0) {
            Log::warning('Биллинг: баланс ушёл в отрицательное значение', [
                'license_id' => $license->id,
                'user_id' => $wallet->user_id,
                'amount' => $amount,
                'remaining_balance' => $wallet->balance,
            ]);
            $this->results['negative']++;
        }

        Log::info('Биллинг: успешное списание', [
            'license_id' => $license->id,
            'user_id' => $wallet->user_id,
            'amount' => $amount,
            'remaining_balance' => $wallet->balance,
        ]);

        $this->results['charged']++;
    }
}
