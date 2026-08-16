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
        $morphMap   = config('component_morphotypes', []);
        $roleBook   = config('component_roles.roles', []);

        if (empty($templates)) {
            $this->warn('config/templates.php пуст или отсутствует — нечего сеять.');
            return self::FAILURE;
        }

        // Целостность ролей проверяется ДО первой записи, потому что внешнего ключа
        // на справочник нет: он живёт в конфиге, а не в таблице (см. шапку миграции
        // create_component_variant_role_table). Единственное место, где рассинхрон
        // между component_morphotypes.php и component_roles.php можно поймать, —
        // здесь, и ловить его надо до того, как половина ролей уже записана.
        $unknownRoles = $this->findUnknownRoles($morphMap, $roleBook);

        if ($unknownRoles !== []) {
            $this->error('Роли из config/component_morphotypes.php отсутствуют в справочнике:');
            foreach ($unknownRoles as $slug => $where) {
                $this->error("  • {$slug} — {$where}");
            }
            $this->line('');
            $this->line('Добавьте их в config/component_roles.php либо перегенерируйте морфотипы:');
            $this->line('  node scripts/build-component-morphotypes.mjs');
            $this->line('Каталог не изменён.');

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
        $morphed = 0;
        $morphMissing = [];

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

                        $variant = $registrar->ensureVariant(
                            $component,
                            $version,
                            null,
                            $isLegacy ? ComponentVariant::STATUS_LEGACY : ComponentVariant::STATUS_ACTIVE,
                        );
                        $variants++;
                        $legacy += $isLegacy ? 1 : 0;

                        // Ключ морфотипа повторяет уникальность каталога —
                        // «шаблон + страница + тип», а не «шаблон + тип». `Hero`
                        // в одном шаблоне на /about и на /actions — разные
                        // конструкции, и укороченный ключ схлопнул бы их в одну.
                        $entry = $morphMap[$templateId][$slug][$type][$version] ?? null;

                        if ($entry === null) {
                            // Не ошибка: layout-компоненты объявлены в каталоге не
                            // всеми своими версиями (у Header в коде три, в БД одна).
                            // Собираем в отчёт, чтобы расхождение было видно.
                            $morphMissing[] = "{$templateId} {$slug} {$type} v{$version}";
                            continue;
                        }

                        $registrar->applyMorphotype(
                            $variant,
                            $entry['morph'] ?? null,
                            $entry['roles'] ?? [],
                        );
                        $morphed++;
                    }
                }
                $pages++;
            }
        }

        $this->info("Каталог компонентов: обработано страниц={$pages}, компонентов={$components}, схем={$variants}.");
        $this->info("Выведено из обращения: компонентов={$retired}, версий={$legacy}.");
        $this->info("Морфотипы: проставлено={$morphed}, без записи в конфиге=" . count($morphMissing) . '.');

        if ($morphMissing !== []) {
            $this->line('Версии без морфотипа (конструкция не выписана — блок работает, но не ищется в библиотеке):');
            foreach ($morphMissing as $line) {
                $this->line("  • {$line}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Роли, встречающиеся в морфотипах, но отсутствующие в справочнике.
     *
     * Возвращает slug => «где впервые встретился», чтобы сообщение об ошибке
     * показывало не только чего не хватает, но и куда идти смотреть.
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
