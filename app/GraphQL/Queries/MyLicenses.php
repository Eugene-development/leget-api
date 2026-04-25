<?php

namespace App\GraphQL\Queries;

use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use GraphQL\Type\Definition\ResolveInfo;

final class MyLicenses
{
    /**
     * Return all licenses owned by the authenticated user.
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info)
    {
        return $context->user()->licenses()->get();
    }
}
