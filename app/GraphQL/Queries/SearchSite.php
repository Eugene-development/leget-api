<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Services\CatalogVisibility;
use App\Services\SiteSearch;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class SearchSite
{
    public function __construct(private SiteSearch $search) {}

    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $query = trim($args['query']);
        if (mb_strlen($query) < 3) {
            return ['items' => [], 'total' => 0];
        }
        $request = $context->request();
        $domain = $request->header('X-Forwarded-Host') ?? $request->getHost();
        $license = License::where('domain', $domain)->first();
        if (! $license || ! $license->is_active || $license->status === 'suspended') {
            throw new GraphQLException('Site not found', 'SITE_NOT_FOUND');
        }
        $rateKey = 'site-search:'.$license->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 60)) {
            throw new GraphQLException('Слишком много запросов. Повторите через минуту.', 'RATE_LIMITED');
        }
        RateLimiter::hit($rateKey, 60);

        $key = 'site-search:v4:'.$license->id.app(CatalogVisibility::class)->cacheSuffix($license);
        try {
            $cache = Cache::tags(['license:'.$license->id]);
        } catch (\BadMethodCallException) {
            $cache = Cache::store();
        }
        $documents = $cache->remember($key, 60, fn () => $this->search->documents($license));

        return SiteSearch::search($documents, $query, $args['offset'] ?? 0);
    }
}
