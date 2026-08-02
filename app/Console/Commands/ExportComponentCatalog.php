<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ComponentVariant;
use Illuminate\Console\Command;

/**
 * Выгружает каталог артикулов (template_pages + components + component_variants)
 * в JSON на stdout.
 *
 * Нужен генератору карты «артикул → файл» (scripts/build-component-articles-map.mjs):
 * доступ к MySQL остаётся в leget-api, генератор карты работает уже с JSON и
 * исходниками leget-main. Ничего не пишет в БД — только чтение.
 *
 * Пример: php artisan component-catalog:export > /tmp/catalog.json
 */
class ExportComponentCatalog extends Command
{
    protected $signature = 'component-catalog:export';

    protected $description = 'Выгрузить каталог артикулов компонентов в JSON (stdout)';

    public function handle(): int
    {
        $rows = ComponentVariant::query()
            ->join('components', 'components.id', '=', 'component_variants.component_id')
            ->join('template_pages', 'template_pages.id', '=', 'components.page_id')
            ->orderBy('components.template_id')
            ->orderBy('template_pages.page_number')
            ->orderBy('components.component_number')
            ->orderBy('component_variants.version')
            ->get([
                'component_variants.article as article',
                'component_variants.version as version',
                'components.template_id as template_id',
                'components.type as type',
                'components.component_number as component_number',
                'template_pages.slug as page_slug',
                'template_pages.page_number as page_number',
            ])
            ->map(fn ($r) => [
                'article'          => (string) $r->article,
                'template_id'      => (int) $r->template_id,
                'page_slug'        => (string) $r->page_slug,
                'page_number'      => (int) $r->page_number,
                'type'             => (string) $r->type,
                'component_number' => (int) $r->component_number,
                'version'          => (int) $r->version,
            ])
            ->all();

        // Пишем напрямую в stdout: $this->line() добавляет форматирование Symfony,
        // а вывод должен оставаться валидным JSON для пайпа.
        fwrite(STDOUT, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

        return self::SUCCESS;
    }
}
