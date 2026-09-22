<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

class DailyBillingCommand extends Command
{
    /**
     * Имя и сигнатура Artisan-команды.
     *
     * @var string
     */
    protected $signature = 'app:daily-billing';

    /**
     * Описание команды.
     *
     * @var string
     */
    protected $description = 'Ежедневное списание средств за аренду сайтов';

    /**
     * Выполнение команды.
     *
     * Запускает процесс биллинга и выводит результаты в консоль.
     */
    public function handle(BillingService $billingService): int
    {
        $this->info('Запуск ежедневного биллинга...');
        $this->newLine();

        $results = $billingService->processDailyDebits();

        // Выводим итоговую статистику в консоль
        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Обработано сайтов', $results['processed']],
                ['Учтено расчётных дней', $results['charged']],
                ['Кошельков с отрицательным балансом', $results['negative']],
                ['Ошибки', $results['errors']],
            ]
        );

        if ($results['errors'] > 0) {
            $this->warn('Обнаружены ошибки при обработке. Проверьте логи для деталей.');
        }

        $this->newLine();
        $this->info('Биллинг завершён.');

        return $results['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
