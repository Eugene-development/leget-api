<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\PageComponent;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class TogglePageComponent
{
    /**
     * Toggle the active state of a page component.
     *
     * @param  mixed  $root
     * @param  array{id: string, is_active: bool}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): PageComponent
    {
        $component = PageComponent::find($args['id']);

        if (! $component) {
            throw new GraphQLException('PageComponent not found.', 'VALIDATION');
        }

        $license = $component->license;

        // Verify ownership
        if ($context->user()->id !== $license->user_id) {
            throw new GraphQLException('This action is unauthorized.', 'AUTHORIZATION');
        }

        $component->is_active = $args['is_active'];
        $component->save();

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
