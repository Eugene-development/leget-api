<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Transaction;
use App\Models\Wallet;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class TopUpBalance
{
    /**
     * Пополнение баланса пользователя.
     *
     * Имитирует процесс пополнения: увеличивает баланс кошелька
     * и создаёт транзакцию типа 'deposit'.
     *
     * @param  mixed  $root
     * @param  array{amount: string}  $args
     * @return array{success: bool, newBalance: string, transaction: Transaction}
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $amount = $args['amount'];
        $user = $context->user();

        return DB::transaction(function () use ($user, $amount) {
            // Блокируем кошелёк для обновления (pessimistic locking)
            $wallet = Wallet::where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Увеличиваем баланс (используем bcadd для точности с decimal)
            $wallet->balance = bcadd($wallet->balance, $amount, 2);
            $wallet->save();

            // Создаём запись о транзакции пополнения
            $transaction = Transaction::create([
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'type' => 'deposit',
                'description' => 'Пополнение баланса',
            ]);

            return [
                'success' => true,
                'newBalance' => $wallet->balance,
                'transaction' => $transaction,
            ];
        });
    }
}
