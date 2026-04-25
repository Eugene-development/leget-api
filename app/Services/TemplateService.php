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
     * Seed default page_components for a newly created page.
     *
     * Skips component types that already exist for the page (idempotent).
     * Uses the 'defaults' from config/templates.php as the initial data.
     */
    public function seedDefaultComponents(Page $page, int $templateId): void
    {
        $definitions = $this->getPageComponents($templateId, $page->slug);

        if (empty($definitions)) {
            return;
        }

        // Load existing types to avoid duplicates
        $existingTypes = PageComponent::where('page_id', $page->id)
            ->pluck('type')
            ->flip()
            ->all();

        foreach ($definitions as $sortOrder => $definition) {
            $type = $definition['type'];

            if (isset($existingTypes[$type])) {
                continue;
            }

            PageComponent::create([
                'id'         => (string) Str::ulid(),
                'page_id'    => $page->id,
                'license_id' => $page->license_id,
                'type'       => $type,
                'data'       => $definition['defaults'] ?? [],
                'is_active'  => true,
                'sort_order' => $sortOrder,
            ]);
        }
    }
}
