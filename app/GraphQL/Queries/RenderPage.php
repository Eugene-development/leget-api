<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\Models\Category;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\Rubric;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class RenderPage
{
    public function __construct(
        private \App\Services\TemplateService $templateService
    ) {}

    /**
     * Resolve a public page for the tenant site identified by the request domain.
     *
     * @param  mixed  $root
     * @param  array{slug: string}  $args
     * @return array{site: array{name: ?string, metaDescription: ?string}, page: array{slug: string, componentsData: mixed}}
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $request = $context->request();

        // Resolve domain: X-Forwarded-Host takes priority, fall back to Host header
        $domain = $request->header('X-Forwarded-Host') ?? $request->getHost();

        // Look up the license by domain
        $license = License::where('domain', $domain)->first();

        if (! $license) {
            throw new GraphQLException('Site not found', 'SITE_NOT_FOUND');
        }

        if (! $license->is_active || $license->status === 'suspended') {
            throw new GraphQLException('Site is suspended', 'SITE_SUSPENDED');
        }

        $slug = $args['slug'];
        $cacheKey = "render:{$license->id}:{$slug}";
        $cacheTags = ["license:{$license->id}"];
        $ttl = config('waas.cache_ttl', 3600);

        // Check cache first (use tagged cache if supported, plain cache otherwise)
        $cached = null;
        try {
            $cached = Cache::tags($cacheTags)->get($cacheKey);
        } catch (\BadMethodCallException) {
            $cached = Cache::get($cacheKey);
        }

        if ($cached !== null) {
            return $cached;
        }

        // Cache miss — query the page
        $page = Page::where('license_id', $license->id)
            ->where('slug', $slug)
            ->first();

        // If page doesn't exist in DB, check if it exists in the template config
        if (! $page) {
            $definitions = $this->templateService->getPageComponents((int) $license->template_id, $slug);
            if (empty($definitions)) {
                throw new GraphQLException('Page not found', 'PAGE_NOT_FOUND');
            }

            // Create a virtual page object for the response
            $page = new Page([
                'id' => 0, // Virtual ID
                'license_id' => $license->id,
                'slug' => $slug,
            ]);
        }

        // Use TemplateService to get merged components (handles virtual components too)
        $components = $this->templateService->getMergedPageComponents($license->id, $page);

        // ── Enrich catalog components with live database data ─────────────────
        // For the mebel page, inject real categories from the DB into MebelSidebar
        // so the sidebar always reflects the actual catalog state without extra
        // client-side GraphQL calls.
        $components = $components->map(function ($component) {
            if ($component->type === 'MebelSidebar') {
                $component = clone $component;
                $liveCategories = $this->getMebelCategories();
                if ($liveCategories->isNotEmpty()) {
                    $component->data = array_merge(
                        $component->data ?? [],
                        ['categories' => $liveCategories->toArray()]
                    );
                }
            }

            return $component;
        });
        // ── End enrichment ────────────────────────────────────────────────────

        $response = [
            'site' => [
                'name'            => $license->name,
                'metaDescription' => $license->meta_description,
                'templateId'      => $license->template_id,
                'header'          => $license->header_data ? ['data' => $license->header_data] : null,
                'footer'          => $license->footer_data ? ['data' => $license->footer_data] : null,
            ],
            'page' => [
                'id'             => (string) $page->id,
                'license_id'     => (string) $license->id,
                'slug'           => $page->slug,
                'componentsData' => $components->filter(fn($c) => $c->is_active)->map(fn($c) => ['type' => $c->type, 'data' => $c->data])->values()->all(),
            ],
        ];

        // Store in cache with configurable TTL (default 1 hour)
        if ($ttl > 0) {
            try {
                Cache::tags($cacheTags)->put($cacheKey, $response, $ttl);
            } catch (\BadMethodCallException) {
                Cache::put($cacheKey, $response, $ttl);
            }
        }

        return $response;
    }

    /**
     * Fetch active categories for the «mebel» rubric from the database.
     *
     * Returns a Collection of arrays with keys: id, value, slug, sort_order.
     * Falls back to an empty collection if the rubric doesn't exist yet.
     *
     * @return \Illuminate\Support\Collection<int, array{id: string, value: string, slug: string, sort_order: int}>
     */
    private function getMebelCategories(): \Illuminate\Support\Collection
    {
        $rubric = Rubric::where('slug', 'mebel')
            ->where('is_active', true)
            ->first();

        if (! $rubric) {
            return collect();
        }

        return Category::where('rubric_id', $rubric->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Category $cat) => [
                'id'         => $cat->id,
                'value'      => $cat->value,
                'slug'       => $cat->slug,
                'sort_order' => $cat->sort_order,
            ]);
    }
}
