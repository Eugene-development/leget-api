<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\Category;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class ToggleCategory
{
    /**
     * Toggle the enabled state of a category.
     *
     * @param  mixed  $root
     * @param  array{id: string, is_enabled: bool}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): Category
    {
        $category = Category::find($args['id']);

        if (! $category) {
            throw new GraphQLException('Category not found.', 'VALIDATION');
        }

        $category->is_enabled = $args['is_enabled'];
        $category->save();

        // Invalidate all page/catalog caches to reflect category state immediately
        try {
            Cache::flush();
        } catch (\BadMethodCallException) {
            // If tags are not supported, flush handles it or we swallow the error safely
        }

        return $category;
    }
}
