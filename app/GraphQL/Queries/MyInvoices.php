<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Invoice;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class MyInvoices
{
    /**
     * Билдер для @paginate: счета текущего пользователя, новые первыми.
     *
     * Пагинацию (first / page) навешивает сама директива, поэтому здесь
     * возвращается только запрос без limit/offset.
     *
     * @param  mixed  $root
     * @param  array{}  $args
     * @return Builder<Invoice>
     */
    public function builder($root, array $args, GraphQLContext $context, ResolveInfo $info): Builder
    {
        return Invoice::query()
            ->where('user_id', $context->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
