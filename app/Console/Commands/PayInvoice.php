<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoicePaymentService;
use Illuminate\Console\Command;

class PayInvoice extends Command
{
    /**
     * Имя и сигнатура консольной команды.
     *
     * @var string
     */
    protected $signature = 'invoice:pay {number : Номер счёта, например 202605-00001, либо ID счёта}';

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
            ->orWhere('id', (is_numeric($number) ? (int) $number : 0))
            ->first();

        if (! $invoice) {
            $this->error("Счёт \"{$number}\" не найден в базе данных.");

            return 1;
        }

        if ($invoice->status === 'paid') {
            $this->warn("Счёт №{$invoice->number} уже имеет статус \"Оплачен\" (".($invoice->paid_at ? $invoice->paid_at->format('d.m.Y H:i:s') : 'дата неизвестна').').');

            return 0;
        }

        if ($invoice->status === 'cancelled') {
            $this->error("Счёт №{$invoice->number} был отменён. Нельзя оплатить отменённый счёт.");

            return 1;
        }

        $this->info("Найден счёт №{$invoice->number} на сумму {$invoice->amount} ₽.");
        $this->info("Плательщик: {$invoice->company_name} (ИНН: ".($invoice->inn ?? 'не указан').').');

        if (! $this->confirm('Вы подтверждаете получение оплаты и пополнение баланса пользователя?', true)) {
            $this->warn('Операция отменена.');

            return 0;
        }

        try {
            $paid = app(InvoicePaymentService::class)->pay($invoice->id);
            $this->info("Счёт №{$paid->number} оплачен. Повторного зачисления не производится.");
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return 0;
    }
}
