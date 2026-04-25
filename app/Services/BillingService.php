<?php

namespace App\Services;

use App\Models\Tenant;
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
     * Метод находит все активные сайты (tenants), считывает их daily_price
     * и списывает сумму с кошелька владельца. Баланс может уходить
     * в отрицательное значение — списание производится всегда.
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

        // Получаем все активные сайты с предзагрузкой кошелька владельца.
        // Тенанты со статусом 'cancelled' исключаются — биллинг для них остановлен.
        $activeTenants = Tenant::where('status', 'active')
            ->where('is_active', true)
            ->with('user.wallet')
            ->get();

        Log::info('Биллинг: начало обработки', [
            'total_active_tenants' => $activeTenants->count(),
        ]);

        foreach ($activeTenants as $tenant) {
            $this->results['processed']++;
            $this->processTenant($tenant);
        }

        Log::info('Биллинг: обработка завершена', $this->results);

        return $this->results;
    }

    /**
     * Обработка списания для конкретного сайта (тенанта).
     *
     * Использует Database Transaction и lockForUpdate() для безопасной
     * работы с балансом кошелька, исключая гонку данных (race condition).
     */
    protected function processTenant(Tenant $tenant): void
    {
        try {
            DB::transaction(function () use ($tenant) {
                $user = $tenant->user;

                if (!$user) {
                    Log::warning('Биллинг: у тенанта отсутствует владелец', [
                        'tenant_id' => $tenant->id,
                    ]);
                    $this->results['errors']++;
                    return;
                }

                // Блокируем кошелёк для обновления (pessimistic locking)
                // Это предотвращает одновременное изменение баланса из другого процесса
                $wallet = Wallet::where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (!$wallet) {
                    Log::warning('Биллинг: у пользователя отсутствует кошелёк', [
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                    ]);
                    $this->results['errors']++;
                    return;
                }

                $dailyPrice = $tenant->daily_price;

                // Списываем средства с кошелька (баланс может уйти в минус)
                $this->chargeWallet($wallet, $dailyPrice, $tenant);
            });
        } catch (\Throwable $e) {
            // Ловим любые исключения, чтобы ошибка одного тенанта
            // не прерывала обработку остальных
            Log::error('Биллинг: ошибка при обработке тенанта', [
                'tenant_id' => $tenant->id,
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
    protected function chargeWallet(Wallet $wallet, string $amount, Tenant $tenant): void
    {
        // Уменьшаем баланс кошелька
        $wallet->balance = bcsub($wallet->balance, $amount, 2);
        $wallet->save();

        // Создаём запись о транзакции списания
        Transaction::create([
            'wallet_id' => $wallet->id,
            'amount' => $amount,
            'type' => 'withdraw',
            'description' => "Ежедневное списание за сайт \"{$tenant->name}\" (ID: {$tenant->id})",
        ]);

        // Фиксируем, если баланс ушёл в минус
        if (bccomp($wallet->balance, '0', 2) < 0) {
            Log::warning('Биллинг: баланс ушёл в отрицательное значение', [
                'tenant_id' => $tenant->id,
                'user_id' => $wallet->user_id,
                'amount' => $amount,
                'remaining_balance' => $wallet->balance,
            ]);
            $this->results['negative']++;
        }

        Log::info('Биллинг: успешное списание', [
            'tenant_id' => $tenant->id,
            'user_id' => $wallet->user_id,
            'amount' => $amount,
            'remaining_balance' => $wallet->balance,
        ]);

        $this->results['charged']++;
    }
}
