<?php

namespace App\GraphQL\Queries;

use App\Models\License;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class LicenseSettings
{
    public function __invoke($root, array $args, GraphQLContext $context): License
    {
        return License::where('user_id', $context->user()->id)->findOrFail($args['id']);
    }
}
