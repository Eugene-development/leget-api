<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Component;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Collection;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Список компонентов каталога (для будущего админ-UI).
 */
final class Components
{
    /**
     * @param  mixed  $root
     * @param  array{template_id?: int}  $args
     *
     * @return \Illuminate\Support\Collection<int, Component>
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): Collection
    {
        $query = Component::with(['page', 'variants'])
            ->orderBy('template_id')
            ->orderBy('page_id')
            ->orderBy('component_number');

        if (isset($args['template_id'])) {
            $query->where('template_id', $args['template_id']);
        }

        return $query->get();
    }
}
