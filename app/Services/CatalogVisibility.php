<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\License;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Site overrides never mutate the shared catalog directory. */
final class CatalogVisibility
{
    public const SIDEBARS = [
        'MebelSidebar' => ['mebel', 'categories'],
        'StoleshnicaSidebar' => ['stoleshnica', 'categories'],
        'ByttehnikaSidebar' => ['bytovaya-tehnika', 'brands'],
        'SantehnikaSidebar' => ['santehnika', 'brands'],
        'OsveshchenieSidebar' => ['osveshchenie', 'brands'],
        'OknaSidebar' => ['okna', 'brands'],
        'DveriSidebar' => ['dveri', 'brands'],
        'FurnituraSidebar' => ['furnitura', 'shops'],
        'PliitkaSidebar' => ['plitka', 'brands'],
    ];

    public function enabled(Category $category, License $license): bool
    {
        return (bool) ($license->catalog_settings['categories'][$category->id] ?? $category->is_enabled);
    }

    /** Settings are read from the DB with the license, including on cache hits. */
    public function cacheSuffix(License $license): string
    {
        $settings = $license->catalog_settings ?? [];
        $settings['tag_revision'] = Cache::rememberForever('catalog-tags:revision', fn () => (string) Str::uuid());
        ksort($settings);

        return ':catalog:'.hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
    }
}
