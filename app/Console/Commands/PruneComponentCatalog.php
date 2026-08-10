<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ComponentVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Находит фантомные версии в каталоге — строки `component_variants`, чей номер версии
 * больше объявленного в config/component_variants.php для пары «шаблон + тип».
 *
 * Откуда они взялись: до перевода карты версий на ключ «шаблон + тип» она ключевалась
 * голым именем типа, и одноимённые блоки соседних шаблонов наследовали версии Promo-1.
 * `component-catalog:seed` завёл им артикулы (`3.2.6.2` для `Brands` в Promo-3
 * и `2.8.6.2` для `ActionsCTA` в Promo-2) под несуществующий код. Seed идемпотентен и
 * ничего не удаляет, поэтому исправленный конфиг сам по себе эти строки не уберёт.
 *
 * Почему удаление, а не смена статуса: `legacy` означает «версия была выпущена и
 * выводится из обращения», а фантом не существовал никогда. Правилу иммутабельности
 * артикулов удаление не противоречит — запрещена перенумерация, а дырки допустимы
 * (см. docs/architecture/component-articles.md). Освобождённый артикул закреплён за
 * теми же координатами навсегда: если v2 для этого блока когда-нибудь напишут,
 * seed заведёт ровно тот же `3.2.6.2`.
 *
 * Что команда НЕ трогает: версии, чей компонент или страница исчезли из
 * config/templates.php. Блок может быть убран со страницы временно, и его артикул
 * должен пережить возврат — это отдельное решение, не автоматическая уборка.
 *
 *   php artisan component-catalog:prune            показать найденное, ничего не менять
 *   php artisan component-catalog:prune --force    удалить
 *
 * Без --force ненулевой код возврата означает «фантомы есть» — годится как гейт в CI.
 */
class PruneComponentCatalog extends Command
{
    protected $signature = 'component-catalog:prune {--force : Удалить найденное, а не только показать}';

    protected $description = 'Найти (и с --force удалить) версии компонентов, которых нет в config/component_variants.php';

    public function handle(): int
    {
        $variantMap = config('component_variants', []);

        $rows = ComponentVariant::query()
            ->join('components', 'components.id', '=', 'component_variants.component_id')
            ->join('template_pages', 'template_pages.id', '=', 'components.page_id')
            ->orderBy('components.template_id')
            ->orderBy('template_pages.page_number')
            ->orderBy('components.component_number')
            ->orderBy('component_variants.version')
            ->get([
                'component_variants.id as id',
                'component_variants.article as article',
                'component_variants.version as version',
                'components.template_id as template_id',
                'components.type as type',
                'template_pages.slug as page_slug',
            ]);

        $phantoms = $rows->filter(function ($row) use ($variantMap): bool {
            $max = (int) ($variantMap[(int) $row->template_id][$row->type] ?? 1);

            return (int) $row->version > $max;
        })->values();

        if ($phantoms->isEmpty()) {
            $this->info('Фантомных версий нет: каталог совпадает с config/component_variants.php.');

            return self::SUCCESS;
        }

        $this->warn("Версий без кода: {$phantoms->count()}.");

        $this->table(
            ['Артикул', 'Шаблон', 'Страница', 'Тип', 'Версия'],
            $phantoms->map(fn ($row) => [
                $row->article,
                $row->template_id,
                $row->page_slug,
                $row->type,
                "v{$row->version}",
            ])->all(),
        );

        if (! $this->option('force')) {
            $this->line('Ничего не удалено. Повторите с --force, если список верен.');

            return self::FAILURE;
        }

        $ids = $phantoms->pluck('id')->all();

        DB::transaction(function () use ($ids): void {
            // Явно, не полагаясь на ON DELETE CASCADE: снимать членство в дизайн-системах
            // должно одинаково на любом драйвере.
            DB::table('component_variant_design_system')
                ->whereIn('component_variant_id', $ids)
                ->delete();

            ComponentVariant::whereIn('id', $ids)->delete();
        });

        $this->info('Удалено версий: ' . count($ids) . '. Пересоберите карту артикулов: node scripts/build-component-articles-map.mjs');

        return self::SUCCESS;
    }
}
