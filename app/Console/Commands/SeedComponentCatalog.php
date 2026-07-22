<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ComponentRegistrar;
use Illuminate\Console\Command;

/**
 * Наполняет каталог компонентов (template_pages, components, component_variants)
 * из config/templates.php и config/component_variants.php.
 *
 * Идемпотентно (через ComponentRegistrar): безопасно запускать повторно.
 */
class SeedComponentCatalog extends Command
{
    protected $signature = 'component-catalog:seed';

    protected $description = 'Заполнить каталог компонентов (артикулы) из config/templates.php';

    public function handle(ComponentRegistrar $registrar): int
    {
        $templates = config('templates', []);
        $variantMap = config('component_variants', []);

        if (empty($templates)) {
            $this->warn('config/templates.php пуст или отсутствует — нечего сеять.');
            return self::FAILURE;
        }

        $pages = 0;
        $components = 0;
        $variants = 0;

        foreach ($templates as $templateId => $template) {
            foreach (($template['pages'] ?? []) as $slug => $defs) {
                foreach ($defs as $def) {
                    $type = $def['type'] ?? null;
                    if (! $type) {
                        continue;
                    }

                    $component = $registrar->ensureComponent(
                        (int) $templateId,
                        (string) $slug,
                        (string) $type,
                    );
                    $components++;

                    $max = (int) ($variantMap[$type] ?? 1);
                    for ($version = 1; $version <= $max; $version++) {
                        $registrar->ensureVariant($component, $version);
                        $variants++;
                    }
                }
                $pages++;
            }
        }

        $this->info("Каталог компонентов: обработано страниц={$pages}, компонентов={$components}, схем={$variants}.");
        return self::SUCCESS;
    }
}
