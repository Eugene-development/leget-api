<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Services\PublicSitePages;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class SiteMap
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $request = $context->request();
        $license = License::where('domain', $request->header('X-Forwarded-Host') ?? $request->getHost())->first();
        if (! $license || ! $license->is_active || $license->status === 'suspended') {
            throw new GraphQLException('Site not found', 'SITE_NOT_FOUND');
        }

        return app(PublicSitePages::class)->paths($license);
    }
}
