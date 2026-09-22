<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class WalletTransactions
{
    public function builder(Wallet $wallet, array $args, GraphQLContext $context): Builder
    {
        abort_unless($wallet->user_id === $context->user()->id, 403);
        $query = Transaction::where('wallet_id', $wallet->id);
        if (isset($args['type'])) {
            $query->where('type', $args['type']);
        }
        // Billing and the balance calendar use Moscow days, including boundary timestamps.
        if (isset($args['dateFrom'])) {
            $query->where('created_at', '>=', Carbon::parse($args['dateFrom'], 'Europe/Moscow')->startOfDay()->timezone(config('app.timezone')));
        }
        if (isset($args['dateTo'])) {
            $query->where('created_at', '<', Carbon::parse($args['dateTo'], 'Europe/Moscow')->startOfDay()->addDay()->timezone(config('app.timezone')));
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
