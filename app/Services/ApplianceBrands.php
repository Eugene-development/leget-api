<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GraphQLException;
use App\Models\ApplianceBrand;
use App\Models\Category;
use App\Models\License;
use App\Models\Page;
use App\Models\Rubric;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ApplianceBrands
{
    public const RUBRICS = [
        'bytovaya-tehnika' => ['hero' => 'ByttehnikaBrandHero', 'tags' => 'appliance-type'],
        'santehnika' => ['hero' => 'SantehnikaBrandHero', 'tags' => 'plumbing-type'],
    ];

    public function definition(string $rubric): array
    {
        return self::RUBRICS[$rubric] ?? throw new GraphQLException('Неизвестная рубрика брендов.', 'VALIDATION');
    }

    /** Read-only category-shaped entries keep existing URLs and sidebar IDs stable. */
    public function entries(License $license, string $rubric = 'bytovaya-tehnika'): Collection
    {
        $definition = $this->definition($rubric);
        if (! Rubric::where('slug', $rubric)->where('is_active', true)->exists()) {
            return collect();
        }
        $shared = Category::where('is_active', true)
            ->whereHas('rubric', fn ($q) => $q->where('slug', $rubric)->where('is_active', true))
            ->orderBy('sort_order')->get();
        $custom = ApplianceBrand::withTrashed()->where('license_id', $license->id)->where('rubric_slug', $rubric)->with('tags')->get();
        $overrides = $custom->whereNotNull('source_category_id')->keyBy('source_category_id');
        // Preserve content previously edited directly on a concrete brand page.
        $pages = Page::where('license_id', $license->id)
            ->whereIn('slug', $shared->map(fn ($category) => '/'.$rubric.'/'.$category->slug)->push('/'.$rubric.'/{brand}'))
            ->with(['pageComponents' => fn ($q) => $q->where('license_id', $license->id)])->get()->keyBy('slug');
        $entries = $shared->map(function (Category $category) use ($overrides, $pages, $rubric, $definition) {
            $override = $overrides->get($category->id);
            if ($override?->trashed()) {
                return null;
            }
            $entry = clone $category;
            $entry->setAttribute('hero_description', $category->description);
            $saved = ($pages->get('/'.$rubric.'/'.$category->slug) ?? $pages->get('/'.$rubric.'/{brand}'))?->pageComponents->keyBy('type');
            $entry->setAttribute('logo', $saved?->get($definition['hero'])?->data['logo'] ?? null);
            if ($saved?->get('BrandAbout') && array_key_exists('description', $saved->get('BrandAbout')->data ?? [])) {
                $entry->setAttribute('description', $saved->get('BrandAbout')->data['description']);
            }
            $entry->setRelation('tags', collect());
            $entry->setAttribute('has_brand_content', false);
            if ($override) {
                foreach (['value', 'description', 'logo'] as $key) {
                    $entry->setAttribute($key, $override->$key);
                }
                $entry->setRelation('tags', $override->tags);
                $entry->setAttribute('has_brand_content', true);
            }

            return $entry;
        })->filter();
        foreach ($custom->whereNull('source_category_id')->filter(fn ($brand) => ! $brand->trashed()) as $brand) {
            $entry = new Category;
            $entry->forceFill($brand->only(['id', 'slug', 'value', 'description', 'logo', 'sort_order']));
            $entry->forceFill(['is_active' => true, 'is_enabled' => true, 'has_brand_content' => true, 'tag_target_type' => 'site_brand']);
            $entry->setRelation('tags', $brand->tags);
            $entries->push($entry);
        }

        return $entries->sortBy('sort_order')->values();
    }

    public function authorize(string $licenseId, mixed $user): License
    {
        $license = $user?->licenses()->find($licenseId);
        if (! $license) {
            throw new GraphQLException('Нет доступа к брендам этого сайта.', 'FORBIDDEN');
        }

        return $license;
    }

    /** Caller holds the license row lock, serializing edits and slug allocation. */
    public function editable(License $license, string $id, string $rubric = 'bytovaya-tehnika'): ApplianceBrand
    {
        $brand = ApplianceBrand::where('license_id', $license->id)->where('rubric_slug', $rubric)
            ->where(fn ($q) => $q->whereKey($id)->orWhere('source_category_id', $id))->first();
        if ($brand) {
            return $brand;
        }
        $shared = Category::whereKey($id)->where('is_active', true)
            ->whereHas('rubric', fn ($q) => $q->where('slug', $rubric)->where('is_active', true))->first();
        if (! $shared || ApplianceBrand::withTrashed()->where('license_id', $license->id)->where('rubric_slug', $rubric)->where('source_category_id', $id)->exists()) {
            throw new GraphQLException('Бренд не найден или уже удалён.', 'NOT_FOUND');
        }

        return new ApplianceBrand([
            'rubric_slug' => $rubric, 'license_id' => $license->id, 'source_category_id' => $shared->id,
            'slug' => $shared->slug, 'value' => $shared->value,
            'description' => $shared->description, 'sort_order' => $shared->sort_order,
        ]);
    }

    public function changed(License $license): void
    {
        $settings = $license->catalog_settings ?? [];
        $settings['appliance_brands_revision'] = (string) Str::uuid();
        $license->catalog_settings = $settings;
        $license->save();
    }
}
