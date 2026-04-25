<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class RenderPage
{
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

        if (! $page) {
            throw new GraphQLException('Page not found', 'PAGE_NOT_FOUND');
        }

        $components = PageComponent::where('page_id', $page->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(fn($c) => ['type' => $c->type, 'data' => $c->data])
            ->values()
            ->all();

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
                'componentsData' => $components,
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
}
