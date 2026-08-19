<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ComponentVariant;
use App\Services\ComponentRegistrar;
use Illuminate\Console\Command;

/**
 * Наполняет каталог компонентов (template_pages, components, component_variants)
 * из config/templates.php, config/component_variants.php и
 * config/component_lifecycle.php.
 *
 * Идемпотентно (через ComponentRegistrar): безопасно запускать повторно. Вывод
 * из обращения тоже идемпотентен и обратим — убрали запись из конфига, прогнали
 * сеятель, компонент снова предлагается.
 */
class SeedComponentCatalog extends Command
{
    protected $signature = 'component-catalog:seed';

    protected $description = 'Заполнить каталог компонентов (артикулы) из config/templates.php';

    public function handle(ComponentRegistrar $registrar): int
    {
        $templates  = config('templates', []);
        $variantMap = config('component_variants', []);
        $lifecycle  = config('component_lifecycle', []);

        if (empty($templates)) {
            $this->warn('config/templates.php пуст или отсутствует — нечего сеять.');
            return self::FAILURE;
        }

        $retiredMap = $lifecycle['retired'] ?? [];
        $legacyMap  = $lifecycle['legacy'] ?? [];

        // Инвариант проверяется ДО первой записи: половина применённого вывода
        // хуже неприменённого. Компонент, у которого все версии legacy, обязан
        // быть выведен и сам — иначе он предлагается новым сайтам, не имея ни
        // одной конструкции, которую можно выбрать.
        $broken = $this->findComponentsWithoutActiveVersion($templates, $variantMap, $retiredMap, $legacyMap);

        if ($broken !== []) {
            $this->error('Все версии выведены, а сам компонент — нет:');
            foreach ($broken as $line) {
                $this->error("  • {$line}");
            }
            $this->line('');
            $this->line('Добавьте тип в `retired` конфига component_lifecycle.php либо оставьте');
            $this->line('хотя бы одну версию вне `legacy`. Каталог не изменён.');

            return self::FAILURE;
        }

        $pages = 0;
        $components = 0;
        $variants = 0;
        $retired = 0;
        $legacy = 0;

        foreach ($templates as $templateId => $template) {
            foreach (($template['pages'] ?? []) as $slug => $defs) {
                foreach ($defs as $def) {
                    $type = $def['type'] ?? null;
                    if (! $type) {
                        continue;
                    }

                    $isRetired = in_array($type, $retiredMap[$templateId][$slug] ?? [], true);

                    $component = $registrar->ensureComponent(
                        (int) $templateId,
                        (string) $slug,
                        (string) $type,
                        null,
                        ! $isRetired,
                    );
                    $components++;
                    $retired += $isRetired ? 1 : 0;

                    // Ключ — пара «шаблон + тип»: одноимённые блоки разных шаблонов
                    // независимы, и версии Promo-1 не должны утекать соседям.
                    $max = (int) ($variantMap[$templateId][$type] ?? 1);
                    $legacyVersions = $legacyMap[$templateId][$type] ?? [];

                    for ($version = 1; $version <= $max; $version++) {
                        $isLegacy = in_array($version, $legacyVersions, true);

                        $registrar->ensureVariant(
                            $component,
                            $version,
                            null,
                            $isLegacy ? ComponentVariant::STATUS_LEGACY : ComponentVariant::STATUS_ACTIVE,
                        );
                        $variants++;
                        $legacy += $isLegacy ? 1 : 0;
                    }
                }
                $pages++;
            }
        }

        $this->info("Каталог компонентов: обработано страниц={$pages}, компонентов={$components}, схем={$variants}.");
        $this->info("Выведено из обращения: компонентов={$retired}, версий={$legacy}.");

        return self::SUCCESS;
    }

    /**
     * Компоненты, у которых не осталось ни одной активной версии, но сами они
     * не выведены.
     *
     * @param  array<mixed>  $templates
     * @param  array<mixed>  $variantMap
     * @param  array<mixed>  $retiredMap
     * @param  array<mixed>  $legacyMap
     * @return list<string>
     */
    private function findComponentsWithoutActiveVersion(
        array $templates,
        array $variantMap,
        array $retiredMap,
        array $legacyMap,
    ): array {
        $broken = [];

        foreach ($templates as $templateId => $template) {
            foreach (($template['pages'] ?? []) as $slug => $defs) {
                foreach ($defs as $def) {
                    $type = $def['type'] ?? null;
                    if (! $type) {
                        continue;
                    }

                    if (in_array($type, $retiredMap[$templateId][$slug] ?? [], true)) {
                        continue;
                    }

                    $max = (int) ($variantMap[$templateId][$type] ?? 1);
                    $legacyVersions = $legacyMap[$templateId][$type] ?? [];

                    $hasActive = false;
                    for ($version = 1; $version <= $max; $version++) {
                        if (! in_array($version, $legacyVersions, true)) {
                            $hasActive = true;
                            break;
                        }
                    }

                    if (! $hasActive) {
                        $broken[] = "шаблон {$templateId}, страница {$slug}, тип {$type} ({$max} верс.)";
                    }
                }
            }
        }

        return $broken;
    }
}
