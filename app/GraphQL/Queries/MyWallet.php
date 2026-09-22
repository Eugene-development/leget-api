<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Wallet;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class MyWallet
{
    /**
     * Возвращает кошелёк текущего аутентифицированного пользователя.
     *
     * @param  mixed  $root
     * @param  array{}  $args
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): Wallet
    {
        return Wallet::forUser($context->user()->id);
    }
}
