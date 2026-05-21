<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PayInvoice extends Command
{
    /**
     * Имя и сигнатура консольной команды.
     *
     * @var string
     */
    protected $signature = 'invoice:pay {number : Номер счёта, например INV-202605-00001 или ID счёта}';

    /**
     * Описание консольной команды.
     *
     * @var string
     */
    protected $description = 'Провести оплату по выставленному счёту, изменить статус и пополнить баланс кошелька';

    /**
     * Выполнение консольной команды.
     */
    public function handle(): int
    {
        $number = $this->argument('number');

        // Ищем счёт по номеру или по ID
        $invoice = Invoice::where('number', $number)
            ->orWhere('id', (is_numeric($number) ? (int)$number : 0))
            ->first();

        if (!$invoice) {
            $this->error("Счёт \"{$number}\" не найден в базе данных.");
            return 1;
        }

        if ($invoice->status === 'paid') {
            $this->warn("Счёт №{$invoice->number} уже имеет статус \"Оплачен\" (" . ($invoice->paid_at ? $invoice->paid_at->format('d.m.Y H:i:s') : 'дата неизвестна') . ").");
            return 0;
        }

        if ($invoice->status === 'cancelled') {
            $this->error("Счёт №{$invoice->number} был отменён. Нельзя оплатить отменённый счёт.");
            return 1;
        }

        $this->info("Найден счёт №{$invoice->number} на сумму {$invoice->amount} ₽.");
        $this->info("Плательщик: {$invoice->company_name} (ИНН: " . ($invoice->inn ?? 'не указан') . ").");

        if (!$this->confirm('Вы подтверждаете получение оплаты и пополнение баланса пользователя?', true)) {
            $this->warn('Операция отменена.');
            return 0;
        }

        DB::transaction(function () use ($invoice) {
            // Блокируем кошелёк для безопасного обновления баланса
            $wallet = Wallet::where('id', $invoice->wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Пополняем баланс
            $oldBalance = $wallet->balance;
            $wallet->balance = bcadd($wallet->balance, $invoice->amount, 2);
            $wallet->save();

            // Создаем транзакцию
            $transaction = Transaction::create([
                'wallet_id' => $wallet->id,
                'amount' => $invoice->amount,
                'type' => 'deposit',
                'description' => "Оплата по безналичному счёту №{$invoice->number}",
            ]);

            // Обновляем статус счёта
            $invoice->status = 'paid';
            $invoice->paid_at = now();
            $invoice->save();

            $this->info("Баланс кошелька успешно обновлен: {$oldBalance} ₽ -> {$wallet->balance} ₽.");
            $this->info("Создана транзакция ID: {$transaction->id}.");
            $this->info("Статус счёта изменен на \"Оплачен\" (paid).");
        });

        return 0;
    }
}
