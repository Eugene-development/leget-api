<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Invoice;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class MyInvoices
{
    /**
     * Возвращает список счетов текущего пользователя (новые первыми).
     *
     * @param  mixed  $root
     * @param  array{}  $args
     * @return \Illuminate\Database\Eloquent\Collection<Invoice>
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info)
    {
        return Invoice::where('user_id', $context->user()->id)
            ->orderByDesc('created_at')
            ->get();
    }
}
