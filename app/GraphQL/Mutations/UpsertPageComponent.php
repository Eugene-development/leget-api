<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpsertPageComponent
{
    /**
     * Create or update a page component.
     *
     * @param  mixed  $root
     * @param  array{page_id: int|string, license_id: string, type: string, data: mixed}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): PageComponent
    {
        $license = License::find($args['license_id']);

        if (! $license) {
            throw new GraphQLException('License not found.', 'VALIDATION');
        }

        // Verify ownership
        if ($context->user()->id !== $license->user_id) {
            throw new GraphQLException('This action is unauthorized.', 'AUTHORIZATION');
        }

        $page = Page::where('id', $args['page_id'])
            ->where('license_id', $license->id)
            ->first();

        if (! $page) {
            throw new GraphQLException('Page not found.', 'VALIDATION');
        }

        $component = PageComponent::updateOrCreate(
            [
                'page_id' => $page->id,
                'type'    => $args['type'],
            ],
            [
                'data'       => $args['data'],
                'license_id' => $license->id,
            ]
        );

        // Invalidate cache for the license (tagged if supported, plain otherwise)
        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException) {
            // database/file cache stores don't support tagging — flush all
            Cache::flush();
        }

        return $component;
    }
}
