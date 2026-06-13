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
            $pageId = (string) $page->id;
        } else {
            $pageId = $pageOrId;
            $page = Page::where('id', $pageId)->where('license_id', $licenseId)->first();
        }

        // If page is null (virtual or deleted), we can't merge DB components effectively
        // but we can still return defaults if we know the slug.
        // For virtual pages passed as objects, we have the slug.
        $slug = $templateSlug ?? ($page ? $page->slug : ''); 
        $definitions = $this->getPageComponents((int) $license->template_id, $slug);
        
        $dbComponents = $page && $page->exists
            ? PageComponent::where('page_id', $page->id)
                ->where('license_id', $licenseId)
                ->get()
                ->keyBy('type')
            : collect();

        if (empty($definitions)) {
            return $dbComponents->sortBy('sort_order')->values();
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
                    'page_id'    => $page->id,
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

        return $result->sortBy('sort_order')->values();
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
