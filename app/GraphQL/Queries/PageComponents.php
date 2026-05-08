<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\PageComponent;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class PageComponents
{
    public function __construct(
        private \App\Services\TemplateService $templateService
    ) {}

    /**
     * Return all components for a page, verifying license ownership.
     *
     * @param  mixed  $root
     * @param  array{licenseId: string, pageId: string}  $args
     * @return \Illuminate\Database\Eloquent\Collection
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info)
    {
        $user = $context->user();

        // Verify the license belongs to the authenticated user
        $license = License::where('id', $args['licenseId'])
            ->where('user_id', $user->id)
            ->first();

        if (! $license) {
            throw new GraphQLException('License not found', 'LICENSE_NOT_FOUND');
        }

        return $this->templateService->getMergedPageComponents($args['licenseId'], $args['pageId']);
    }
}
