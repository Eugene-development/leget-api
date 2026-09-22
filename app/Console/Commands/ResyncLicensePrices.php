<?php

namespace App\Console\Commands;

use App\Models\License;
use App\Services\BillingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ResyncLicensePrices extends Command
{
    /**
     * Имя и сигнатура Artisan-команды.
     *
     * @var string
     */
    protected $signature = 'app:resync-license-prices
                            {--apply : Записать изменения в БД (без флага команда только показывает план)}
                            {--template= : Ограничить одним шаблоном (template_id)}
                            {--all-statuses : Включить и неактивные, и отменённые лицензии}';

    /**
     * Описание команды.
     *
     * @var string
     */
    protected $description = 'Пересинхронизировать licenses.daily_price с ценами шаблонов из config/waas.php';

    /**
     * Выполнение команды.
     *
     * Цена шаблона живёт в config/waas.php и попадает в лицензию только при её
     * создании (CreateLicense) или смене шаблона (UpdateLicense). Уже выданные
     * лицензии продолжают списывать записанную сумму, поэтому после правки
     * конфига их нужно догнать — этим и занимается команда.
     */
    public function handle(): int
    {
        $prices = config('waas.template_prices', []);

        if (empty($prices)) {
            $this->error('config/waas.php: template_prices пуст — нечего синхронизировать.');

            return self::FAILURE;
        }

        $onlyTemplate = $this->option('template');
        $apply = (bool) $this->option('apply');
        $allStatuses = (bool) $this->option('all-statuses');

        $rows = [];
        $totalAffected = 0;

        foreach ($prices as $templateId => $price) {
            if ($onlyTemplate !== null && (string) $templateId !== (string) $onlyTemplate) {
                continue;
            }

            $query = $this->scope($allStatuses)
                ->where('template_id', $templateId)
                ->where('daily_price', '!=', $price);

            // Снимок «было» до записи: после UPDATE эти значения уже не восстановить.
            $breakdown = (clone $query)
                ->select('daily_price', DB::raw('COUNT(*) as total'))
                ->groupBy('daily_price')
                ->pluck('total', 'daily_price');

            foreach ($breakdown as $oldPrice => $count) {
                $rows[] = [$templateId, $oldPrice, $price, $count];
                $totalAffected += $count;
            }

            if ($apply && $breakdown->isNotEmpty()) {
                foreach ((clone $query)->pluck('id') as $id) {
                    DB::transaction(function () use ($id, $price, $templateId, $allStatuses) {
                        $license = License::whereKey($id)->lockForUpdate()->firstOrFail();
                        if ((int) $license->template_id !== (int) $templateId || (! $allStatuses && (! $license->is_active || $license->status !== 'active'))) {
                            return;
                        }
                        app(BillingService::class)->settleLockedLicense($license);
                        $license->update(['daily_price' => $price]);
                    }, 3);
                }
            }
        }

        // Лицензии на шаблон, которого нет в конфиге: цену им взять неоткуда.
        $orphans = $this->scope($allStatuses)
            ->whereNotIn('template_id', array_keys($prices))
            ->count();

        if ($rows === []) {
            $this->info('Все лицензии уже соответствуют ценам из config/waas.php.');
        } else {
            $this->table(['Шаблон', 'Было', 'Станет', 'Лицензий'], $rows);
            $this->newLine();

            if ($apply) {
                $this->info("Обновлено лицензий: {$totalAffected}.");
            } else {
                $this->warn("План: {$totalAffected} лицензий. Ничего не записано — повторите с флагом --apply.");
            }
        }

        if ($orphans > 0) {
            $this->newLine();
            $this->warn("Пропущено {$orphans} лицензий: их template_id отсутствует в config/waas.php.");
        }

        if (! $allStatuses) {
            $this->newLine();
            $this->line('Учтены только действующие лицензии (status=active, is_active=true) — те, что реально биллятся.');
            $this->line('Отменённые и отключённые сохраняют прежнюю цену; чтобы задеть и их, добавьте --all-statuses.');
        }

        return self::SUCCESS;
    }

    /**
     * Базовая выборка лицензий.
     *
     * По умолчанию — ровно тот набор, который забирает BillingService:
     * status=active и is_active=true. Условие billing_started_at сюда не входит
     * намеренно: лицензия с будущей датой старта ещё не биллится, но цену
     * к моменту первого списания должна иметь уже новую.
     */
    protected function scope(bool $allStatuses): Builder
    {
        $query = License::query();

        if (! $allStatuses) {
            $query->where('status', 'active')->where('is_active', true);
        }

        return $query;
    }
}
