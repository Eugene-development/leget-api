<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\License;
use App\Models\MebelProject;

/** Canonical public routes shared by search, tag links and sitemap. */
final class PublicSitePages
{
    public function __construct(private TemplateService $templates, private CatalogVisibility $visibility) {}

    public function paths(License $license): array
    {
        return array_keys($this->sources($license));
    }

    public function destinations(License $license): array
    {
        $result = [];
        foreach ($this->sources($license) as $path => $source) {
            if ($brand = $source['brand'] ?? null) {
                $result['catalog_brand:'.$brand->id] = ['path' => $path, 'name' => $brand->value];
            } elseif (($category = $source['category'] ?? null) && ! isset($source['project'])) {
                $type = $category->tag_target_type ?? 'category';
                $result[$type.':'.$category->id] = ['path' => $path, 'name' => $category->value];
            }
        }

        return $result;
    }

    public function sources(License $license): array
    {
        $definitions = $this->templates->getTemplate((int) $license->template_id)['pages'] ?? [];
        $sources = array_fill_keys(array_keys($definitions), []);
        $categories = Category::where('is_active', true)
            ->whereHas('rubric', fn ($q) => $q->where('is_active', true))
            ->with(['rubric', 'brands' => fn ($q) => $q->where('is_active', true)])->get()
            ->filter(fn ($category) => ! isset(ApplianceBrands::RUBRICS[$category->rubric->slug]) && $this->visibility->enabled($category, $license));
        foreach (array_keys(ApplianceBrands::RUBRICS) as $rubric) {
            foreach (app(ApplianceBrands::class)->entries($license, $rubric) as $entry) {
                if ($this->visibility->enabled($entry, $license)) {
                    $sources['/'.$rubric.'/'.$entry->slug] = ['category' => $entry];
                }
            }
        }
        foreach ($categories as $category) {
            $path = '/'.$category->rubric->slug.'/'.$category->slug;
            $sources[$path] = ['category' => $category];
            if ($category->rubric->slug === 'stoleshnica') {
                foreach ($category->brands as $brand) {
                    $sources[$path.'/'.$brand->slug] = ['category' => $category, 'brand' => $brand];
                }
            }
        }
        $furniture = $categories->where('rubric.slug', 'mebel')->keyBy('id');
        foreach (MebelProject::where('is_active', true)->whereIn('category_id', $furniture->keys())
            ->where(fn ($q) => $q->whereNull('license_id')->orWhere('license_id', $license->id))
            ->get() as $project) {
            $category = $furniture[$project->category_id];
            $sources['/mebel/'.$category->slug.'/'.$project->slug] = ['category' => $category, 'project' => $project];
        }

        return array_filter($sources, function ($source, $path) use ($definitions, $license) {
            return SiteSearch::publicPath($path)
                && isset($definitions[$this->templates->resolveTemplateSlug((int) $license->template_id, $path)]);
        }, ARRAY_FILTER_USE_BOTH);
    }
}
