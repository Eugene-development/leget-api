<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Page;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpdatePage
{
    /**
     * Update an existing page (slug only).
     *
     * @param  mixed  $root
     * @param  array{id: string|int, license_id: string, slug?: string}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): Page
    {
        $page = Page::where('id', $args['id'])
            ->where('license_id', $args['license_id'])
            ->first();

        if (! $page) {
            throw new GraphQLException('Page not found.', 'VALIDATION');
        }

        $license = $page->license;

        // Verify ownership
        if ($context->user()->id !== $license->user_id) {
            throw new GraphQLException('This action is unauthorized.', 'AUTHORIZATION');
        }

        // Validate slug uniqueness if slug is being changed
        if (array_key_exists('slug', $args) && $args['slug'] !== $page->slug) {
            $slugExists = Page::where('license_id', $license->id)
                ->where('slug', $args['slug'])
                ->where('id', '!=', $page->id)
                ->exists();

            if ($slugExists) {
                throw new GraphQLException('A page with this slug already exists for this license.', 'VALIDATION');
            }
        }

        if (array_key_exists('slug', $args)) {
            $page->update(['slug' => $args['slug']]);
        }

        // Invalidate cache for the license (tagged if supported, plain otherwise)
        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException) {
            Cache::flush();
        }

        return $page;
    }
}
