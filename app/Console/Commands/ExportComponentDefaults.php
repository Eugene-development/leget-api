<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Выгружает демо-контент блоков (ключи `defaults` из config/templates.php) в JSON.
 *
 * Нужен каталогу дизайн-системы: роут /_ds в leget-main монтирует каждый компонент
 * отдельно, и ему нужны данные. Придумывать их не надо — те же значения уже
 * засеиваются в page_components новому клиенту, то есть это канонический демо-контент.
 *
 * Бэкенд и фронт живут в разных контейнерах, поэтому config/templates.php фронту
 * недоступен — как и config/component_variants.php (см. AGENTS.md). Отсюда экспорт
 * в JSON, ровно как у component-catalog:export.
 *
 * Ничего не читает из БД: источник — только конфиг. Значит команду можно запускать
 * там, где БД недоступна.
 *
 * Пример: php artisan component-defaults:export > ../leget-main/src/lib/ds/defaults.json
 */
class ExportComponentDefaults extends Command
{
    protected $signature = 'component-defaults:export {--stats : Вывести сводку в stderr вместо JSON в stdout}';

    protected $description = 'Выгрузить демо-контент блоков (defaults) из config/templates.php в JSON (stdout)';

    public function handle(): int
    {
        $templates = config('templates', []);

        if ($templates === []) {
            $this->error('config/templates.php пуст или отсутствует.');

            return self::FAILURE;
        }

        // Число версий у пары «шаблон + тип». Ground truth — папки v1…vN в leget-main,
        // но фронту всё равно нужен этот конфиг: он единственное место, где количество
        // объявлено явно (см. шапку config/component_variants.php).
        $variantCounts = config('component_variants', []);

        $out   = [];
        $total = 0;
        $thin  = [];

        foreach ($templates as $templateId => $template) {
            $pages = [];

            foreach (($template['pages'] ?? []) as $slug => $defs) {
                $components = [];

                foreach ($defs as $def) {
                    $type = $def['type'] ?? null;

                    if ($type === null) {
                        continue;
                    }

                    $defaults = $def['defaults'] ?? [];
                    $components[$type] = (object) $defaults;
                    $total++;

                    if ($this->isThin($defaults)) {
                        $thin[] = "{$templateId}{$slug}:{$type}";
                    }
                }

                if ($components !== []) {
                    $pages[$slug] = $components;
                }
            }

            $out[(string) $templateId] = [
                'name'  => $template['name'] ?? "Template {$templateId}",
                'pages' => $pages,
            ];
        }

        // Ровно тот же двухуровневый вид, что и в конфиге: templateId → тип → версий.
        // Каждый уровень кастуем в object, иначе пустой шаблон уедет в JSON как `[]`.
        $versions = [];

        foreach ($variantCounts as $templateId => $types) {
            $versions[(string) $templateId] = (object) $types;
        }

        $out['_versions'] = (object) $versions;

        if ($this->option('stats')) {
            $this->line("типов с defaults: {$total}");
            $this->line('пустых или полупустых: ' . count($thin));

            foreach ($thin as $item) {
                $this->line("  {$item}");
            }

            return self::SUCCESS;
        }

        $this->output->writeln(
            json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return self::SUCCESS;
    }

    /**
     * Демо-контент считается «тонким», если его нет вовсе или пустых полей не меньше
     * половины. Такие блоки отрендерятся пустой рамкой, и карточка каталога будет врать —
     * им нужны фикстуры руками. Это не ошибка экспорта, а список работы: --stats.
     *
     * @param  array<string, mixed>  $defaults
     */
    private function isThin(array $defaults): bool
    {
        if ($defaults === []) {
            return true;
        }

        $blanks = 0;

        foreach ($defaults as $value) {
            if ($value === '' || $value === [] || $value === null) {
                $blanks++;
            }
        }

        return $blanks > 0 && $blanks >= count($defaults) / 2;
    }
}
