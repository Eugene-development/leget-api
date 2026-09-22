<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoicePaymentService;
use Illuminate\Console\Command;

class CancelInvoice extends Command
{
    /**
     * Имя и сигнатура консольной команды.
     *
     * @var string
     */
    protected $signature = 'invoice:cancel {number : Номер счёта, например 202605-00001, либо ID счёта}';

    /**
     * Описание консольной команды.
     *
     * @var string
     */
    protected $description = 'Отменить выставленный счёт (статус cancelled). Баланс не затрагивается';

    /**
     * Выполнение консольной команды.
     */
    public function handle(): int
    {
        $number = (string) $this->argument('number');

        $invoice = Invoice::where('number', $number)
            ->orWhere('id', is_numeric($number) ? (int) $number : 0)
            ->first();

        if (! $invoice) {
            $this->error("Счёт \"{$number}\" не найден в базе данных.");

            return 1;
        }

        if ($invoice->status === 'cancelled') {
            $this->warn("Счёт №{$invoice->number} уже отменён.");

            return 0;
        }

        // Оплаченный счёт отменять нельзя: баланс уже пополнен, откат сломает историю
        if ($invoice->status === 'paid') {
            $this->error(
                "Счёт №{$invoice->number} оплачен ("
                .($invoice->paid_at ? $invoice->paid_at->format('d.m.Y H:i:s') : 'дата неизвестна')
                .'). Отменить его нельзя — баланс уже пополнен.'
            );

            return 1;
        }

        $this->info("Найден счёт №{$invoice->number} на сумму {$invoice->amount} ₽.");
        $this->info("Плательщик: {$invoice->company_name} (ИНН: ".($invoice->inn ?? 'не указан').').');

        if (! $this->confirm('Отменить этот счёт?', true)) {
            $this->warn('Операция отменена.');

            return 0;
        }

        try {
            app(InvoicePaymentService::class)->cancel($invoice->id);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Статус счёта №{$invoice->number} изменён на \"Отменён\" (cancelled).");

        return 0;
    }
}
