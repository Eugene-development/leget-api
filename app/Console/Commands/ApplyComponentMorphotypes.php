<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ComponentVariant;
use App\Services\ComponentRegistrar;
use Illuminate\Console\Command;

/**
 * Проставляет морфотипы и роли версиям, которые УЖЕ есть в каталоге.
 *
 * Отличие от `component-catalog:seed`, ради которого команда и заведена: этот
 * сеятель ничего не создаёт. Ни страниц, ни компонентов, ни версий — только
 * дописывает конструкцию и роли к существующим строкам.
 *
 * Зачем разделять. `component-catalog:seed` — источник истины для всего каталога:
 * он читает config/templates.php и заводит по нему недостающее. Значит, прогнать
 * его на боевой базе можно только тогда, когда конфиг шаблонов в этой рабочей
 * копии полностью готов к выкатке. Морфотипы к составу шаблонов отношения не
 * имеют, и держать их наполнение заложником незавершённых правок в соседнем
 * конфиге неправильно: артикул присваивается один раз и навсегда
 * («без перенумерации»), а морфотип переписывается сколько угодно.
 *
 * Идемпотентна. Роли синхронизируются полностью: роль, убранная из конфига,
 * исчезает и из таблицы. Морфотип перезаписывается только непустым значением.
 *
 * Модель: docs/architecture/component-morphotypes.md
 */
class ApplyComponentMorphotypes extends Command
{
    protected $signature = 'component-catalog:morph {--dry-run : Показать, что изменится, и ничего не писать}';

    protected $description = 'Проставить морфотипы и роли существующим версиям каталога';

    public function handle(ComponentRegistrar $registrar): int
    {
        $morphMap = config('component_morphotypes', []);
        $roleBook = config('component_roles.roles', []);

        if ($morphMap === []) {
            $this->warn('config/component_morphotypes.php пуст — нечего проставлять.');
            $this->line('Пересобрать: node scripts/build-component-morphotypes.mjs');

            return self::FAILURE;
        }

        // Целостность ролей проверяется ДО первой записи: внешнего ключа на справочник
        // нет, он живёт в конфиге. Половина применённых ролей хуже неприменённых.
        $unknown = $this->findUnknownRoles($morphMap, $roleBook);

        if ($unknown !== []) {
            $this->error('Роли из config/component_morphotypes.php отсутствуют в справочнике:');
            foreach ($unknown as $slug => $where) {
                $this->error("  • {$slug} — {$where}");
            }
            $this->line('Каталог не изменён.');

            return self::FAILURE;
        }

        $dryRun  = (bool) $this->option('dry-run');
        $applied = 0;
        $skipped = [];

        // Одним запросом со связями: иначе на 256 версий уходит столько же пар
        // запросов «найти страницу» + «найти компонент».
        $variants = ComponentVariant::with('component.page')->get();
        $index    = [];

        foreach ($variants as $variant) {
            $component = $variant->component;
            $page      = $component?->page;

            if (! $component || ! $page) {
                continue;
            }

            $index["{$component->template_id}|{$page->slug}|{$component->type}|{$variant->version}"] = $variant;
        }

        foreach ($morphMap as $templateId => $pages) {
            foreach ($pages as $slug => $types) {
                foreach ($types as $type => $versions) {
                    foreach ($versions as $version => $entry) {
                        $key     = "{$templateId}|{$slug}|{$type}|{$version}";
                        $variant = $index[$key] ?? null;

                        if (! $variant) {
                            // Не ошибка: в конфиге есть версии, которых в каталоге нет
                            // (layout-компоненты объявлены не всеми версиями; блок мог
                            // ещё не доехать до этой базы). Создавать их — работа
                            // component-catalog:seed, а не эта.
                            $skipped[] = "{$templateId} {$slug} {$type} v{$version}";
                            continue;
                        }

                        if (! $dryRun) {
                            $registrar->applyMorphotype(
                                $variant,
                                $entry['morph'] ?? null,
                                $entry['roles'] ?? [],
                            );
                        }

                        $applied++;
                    }
                }
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Морфотипы: проставлено={$applied}, пропущено (нет в каталоге)=" . count($skipped) . '.');

        if ($skipped !== []) {
            $this->line('Версии из конфига, которых нет в каталоге:');
            foreach ($skipped as $line) {
                $this->line("  • {$line}");
            }
        }

        if (! $dryRun) {
            $without = ComponentVariant::whereNull('morph')->count();
            $this->info("Версий в каталоге без морфотипа: {$without}.");
        }

        return self::SUCCESS;
    }

    /**
     * Роли, встречающиеся в морфотипах, но отсутствующие в справочнике.
     *
     * @param  array<mixed>  $morphMap
     * @param  array<string, mixed>  $roleBook
     * @return array<string, string>
     */
    private function findUnknownRoles(array $morphMap, array $roleBook): array
    {
        $unknown = [];

        foreach ($morphMap as $templateId => $pages) {
            foreach ($pages as $slug => $types) {
                foreach ($types as $type => $versions) {
                    foreach ($versions as $version => $entry) {
                        foreach (($entry['roles'] ?? []) as $slugRole) {
                            if (isset($roleBook[$slugRole]) || isset($unknown[$slugRole])) {
                                continue;
                            }

                            $unknown[$slugRole] = "шаблон {$templateId}, {$slug}, {$type} v{$version}";
                        }
                    }
                }
            }
        }

        return $unknown;
    }
}
