<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Component;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Найти каталожный компонент по координатам: template + page slug + type.
 *
 * Используется фронтом для отображения артикула на конкретном экземпляре компонента.
 */
final class FindComponent
{
    /**
     * @param  mixed  $root
     * @param  array{template_id: int, slug: string, type: string}  $args
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): ?Component
    {
        return Component::with(['page', 'variants'])
            ->where('template_id', $args['template_id'])
            ->where('type', $args['type'])
            ->whereHas('page', fn ($q) => $q->where('slug', $args['slug']))
            ->first();
    }
}
