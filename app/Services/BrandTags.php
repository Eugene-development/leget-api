<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApplianceBrand;
use App\Models\CatalogBrand;
use App\Models\Category;
use App\Models\License;
use App\Models\Tag;
use App\Models\TagGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Target identity is independent of the rubric in which a tag is selected. */
final class BrandTags
{
    public function sync(Model $brand): void
    {
        if ($brand instanceof ApplianceBrand && $brand->source_category_id) {
            self::changed();

            return; // A site override uses the shared category's stable tag.
        }
        $rubric = $brand instanceof ApplianceBrand ? ($brand->rubric_slug ?? 'bytovaya-tehnika') : $brand->category?->rubric?->slug;
        if ($brand instanceof Category) {
            $rubric = $brand->rubric?->slug;
        }
        $group = match ($rubric) {
            'bytovaya-tehnika' => 'appliance-brand',
            'santehnika' => 'plumbing-brand',
            'osveshchenie' => 'lighting-brand',
            'okna' => 'window-brand',
            'dveri' => 'door-brand',
            'stoleshnica' => $brand instanceof CatalogBrand ? 'countertop-brand' : null,
            default => null,
        };
        if (! $group) {
            return;
        }
        $type = match (true) {
            $brand instanceof ApplianceBrand => 'site_brand',
            $brand instanceof CatalogBrand => 'catalog_brand',
            default => 'category',
        };
        $tag = Tag::where('target_type', $type)->where('target_id', $brand->id)->first() ?? new Tag;
        $tag->forceFill(['target_type' => $type, 'target_id' => $brand->id]);
        $tag->forceFill(['tag_group_id' => TagGroup::where('slug', $group)->sole()->id,
            'name' => Str::squish($brand->value), 'normalized_name' => Str::lower(Str::squish($brand->value)),
            'license_id' => $brand instanceof ApplianceBrand ? $brand->license_id : null]);
        $tag->save();
        self::changed();
    }

    public static function changed(): void
    {
        Cache::forever('catalog-tags:revision', (string) Str::uuid());
    }

    /** Batch resolve destinations against the same public directory used by search/sitemap. */
    public function present(Collection $tags, ?License $license): Collection
    {
        $tags = $tags->filter(fn ($tag) => ! $tag->license_id || $tag->license_id === $license?->id)->values();
        (new \Illuminate\Database\Eloquent\Collection($tags->all()))->loadMissing('group');
        if ($tags->whereNotNull('target_type')->isEmpty()) {
            return $tags->map(fn ($tag) => [...$tag->only(['id', 'name', 'tag_group_id']), 'href' => null, 'managed' => false, 'group' => $tag->group]);
        }
        $destinations = $license ? app(PublicSitePages::class)->destinations($license) : [];

        return $tags->map(function ($tag) use ($destinations) {
            $destination = $destinations[$tag->target_type.':'.$tag->target_id] ?? null;

            return [...$tag->only(['id', 'name', 'tag_group_id']),
                'name' => $destination['name'] ?? $tag->name,
                'href' => $destination['path'] ?? null, 'managed' => (bool) $tag->target_type, 'group' => $tag->group];
        })->values();
    }
}
