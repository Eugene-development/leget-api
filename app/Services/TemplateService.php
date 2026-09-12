<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Page;
use App\Models\PageComponent;
use Illuminate\Support\Str;

/**
 * Provides helpers for working with the template registry (config/templates.php).
 *
 * Responsibilities:
 *   - Expose allowed component types for a given template + slug
 *   - Seed default page_components rows when a new page is created
 */
class TemplateService
{
    /**
     * Return the template config array for the given templateId, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function getTemplate(int $templateId): ?array
    {
        $templates = config('templates', []);
        return $templates[$templateId] ?? null;
    }

    /**
     * Return the ordered list of component definitions for a template page.
     *
     * Each item: ['type' => string, 'defaults' => array]
     *
     * @return list<array{type: string, defaults: array<string, mixed>}>
     */
    public function getPageComponents(int $templateId, string $slug): array
    {
        $template = $this->getTemplate($templateId);
        if (! $template) {
            return [];
        }

        return $template['pages'][$slug] ?? [];
    }

    /**
     * Return only the allowed component type names for a template page.
     *
     * @return list<string>
     */
    public function getAllowedTypes(int $templateId, string $slug): array
    {
        return array_column($this->getPageComponents($templateId, $slug), 'type');
    }

    /** Resolve a concrete URL to its template definition without creating a page. */
    public function resolveTemplateSlug(int $templateId, string $slug): string
    {
        $pages = $this->getTemplate($templateId)['pages'] ?? [];
        if (array_key_exists($slug, $pages)) {
            return $slug;
        }
        foreach (array_keys($pages) as $pattern) {
            $expression = preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($pattern, '#'));
            if (preg_match('#^'.$expression.'$#D', $slug)) {
                return $pattern;
            }
        }

        return $slug;
    }

    /**
     * Types offered when adding a block to the page: allowed minus retired.
     *
     * Отдельный метод, а не фильтр внутри `getAllowedTypes()`, и это не вкусовое
     * разделение. `getAllowedTypes()` отвечает на вопрос «что вообще бывает на
     * этой странице» и обслуживает две вещи, которым вывод из обращения безразличен:
     *
     *   • `UpsertPageComponent` проверяет им допустимость типа при сохранении —
     *     отфильтруй здесь выведенный тип, и тенант, у которого блок уже стоит,
     *     не смог бы его отредактировать;
     *   • `resolveSortOrder()` берёт из него ПОЗИЦИЮ блока на странице —
     *     отфильтруй, и блок при следующем сохранении уехал бы в конец страницы.
     *
     * Ровно в этом и состоит смысл вывода: «не предлагается» ≠ «не работает».
     *
     * @return list<string>
     */
    public function getOfferedTypes(int $templateId, string $slug): array
    {
        // Конфиг берём целиком и индексируем руками: точечная нотация Laravel
        // режет ключ по `.`, а slug страницы — произвольная строка из
        // `config/templates.php`, и точка в ней ключ бы развалила.
        $retiredMap = config('component_lifecycle.retired', []);
        $retired = $retiredMap[$templateId][$slug] ?? [];

        return array_values(array_filter(
            $this->getAllowedTypes($templateId, $slug),
            static fn(string $type): bool => ! in_array($type, $retired, true),
        ));
    }

    /**
     * Return a merged list of components for a page.
     * Combines config/templates.php definitions with actual DB records.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PageComponent>
     */
    public function getMergedPageComponents(string $licenseId, Page|string $pageOrId, ?string $templateSlug = null): \Illuminate\Database\Eloquent\Collection
    {
        $license = \App\Models\License::findOrFail($licenseId);

        if ($pageOrId instanceof Page) {
            $page = $pageOrId;
        } else {
            $page = Page::where('id', $pageOrId)->where('license_id', $licenseId)->first();
        }

        // If page is null (virtual or deleted), we can't merge DB components effectively
        // but we can still return defaults if we know the slug.
        $slug = $templateSlug ?? ($page ? $page->slug : '');
        $definitions = $this->getPageComponents((int) $license->template_id, $slug);

        return $this->mergeDefinitions($license, $page, $definitions);
    }

    /**
     * Глобальные компоненты лицензии — общие для всех страниц сайта.
     * Хранятся на зарезервированной странице со slug '__global__' (контейнер;
     * не маршрутизируется). На данный момент это футер.
     *
     * Логика слияния та же, что у getMergedPageComponents: определения из
     * config/templates.php['__global__'] + актуальные DB-записи страницы
     * '__global__'. Если страница ещё не создана — компонент виртуальный
     * (exists=false). Для шаблонов без '__global__' возвращается пустая коллекция.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PageComponent>
     */
    public function getGlobalComponents(string $licenseId): \Illuminate\Database\Eloquent\Collection
    {
        $license = \App\Models\License::findOrFail($licenseId);
        $definitions = $this->getPageComponents((int) $license->template_id, '__global__');

        if (empty($definitions)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        $page = Page::where('license_id', $licenseId)->where('slug', '__global__')->first();

        return $this->mergeDefinitions($license, $page, $definitions);
    }

    /**
     * Ядро слияния: соединяет определения из config/templates.php с DB-записями страницы.
     * Если для типа есть DB-запись — берём её; иначе создаём виртуальный компонент
     * (exists=false, не сохраняется в БД) с дефолтами.
     *
     * @param  list<array{type: string, defaults: array<string, mixed>}>  $definitions
     * @return \Illuminate\Database\Eloquent\Collection<int, PageComponent>
     */
    private function mergeDefinitions(\App\Models\License $license, ?Page $page, array $definitions): \Illuminate\Database\Eloquent\Collection
    {
        $dbComponents = ($page && $page->exists)
            ? PageComponent::where('page_id', $page->id)
                ->where('license_id', $license->id)
                ->get()
                ->keyBy('type')
            : collect();

        if (empty($definitions)) {
            return $this->applyComponentOrder(new \Illuminate\Database\Eloquent\Collection($dbComponents->sortBy('sort_order')->values()->all()), $page);
        }

        $result = new \Illuminate\Database\Eloquent\Collection();

        foreach ($definitions as $index => $definition) {
            $type = $definition['type'];

            if ($dbComponents->has($type)) {
                $component = $dbComponents->get($type);
                // We could merge data here if we want to support adding new keys to defaults
                // $component->data = array_merge($definition['defaults'] ?? [], $component->data ?? []);
                $result->push($component);
            } else {
                // Create a virtual component (not persisted in DB)
                $component = new PageComponent([
                    'id'         => (string) Str::ulid(),
                    'page_id'    => $page?->id,
                    'license_id' => $license->id,
                    'type'       => $type,
                    'data'       => $definition['defaults'] ?? [],
                    'is_active'  => true,
                    'sort_order' => $index,
                ]);
                // Set exists to false to make sure it's treated as new if someone tries to save it
                $component->exists = false;
                $result->push($component);
            }
        }

        // config/templates.php is the canonical source of template structure and order.
        // Persisted sort_order values may be stale (legacy rows defaulted to zero), so
        // sorting the merged collection would move edited components ahead of defaults.
        return $this->applyComponentOrder($result, $page);
    }

    /** Keep defaults lazy; ignore removed types and append newly introduced blocks. */
    private function applyComponentOrder(\Illuminate\Database\Eloquent\Collection $components, ?Page $page): \Illuminate\Database\Eloquent\Collection
    {
        $order = $page?->component_order ?? [];
        if (empty($order)) {
            return $components->values();
        }
        $positions = array_flip($order);

        return $components->sortBy(fn (PageComponent $component) => $positions[$component->type] ?? count($order))->values();
    }

    /**
     * Seed default page_components for a newly created page.
     *
     * Skips component types that already exist for the page (idempotent).
     * Uses the 'defaults' from config/templates.php as the initial data.
     */
    public function seedDefaultComponents(Page $page, int $templateId): void
    {
        // No longer needed due to lazy loading via getMergedPageComponents
    }
}
