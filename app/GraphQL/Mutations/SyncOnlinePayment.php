<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\Payment;
use App\Models\Wallet;
use App\Services\PaymentService;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use RuntimeException;

final class SyncOnlinePayment
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Подтягивает состояние платежа у ЮKassa и зачисляет деньги, если оплачен.
     *
     * Вызывается фронтом, когда пользователь вернулся со страницы оплаты.
     * Дублирует работу webhook-а и безопасна при повторных вызовах: зачисление
     * защищено transaction_id.
     *
     * @param  mixed  $root
     * @param  array{id: string}  $args
     * @return array<string, mixed>
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $user = $context->user();

        $payment = Payment::where('id', $args['id'])
            ->where('user_id', $user->id)
            ->first();

        if (! $payment) {
            throw new GraphQLException('Платёж не найден.', 'VALIDATION');
        }

        try {
            $payment = $this->payments->sync($payment);
        } catch (RuntimeException $e) {
            throw new GraphQLException($e->getMessage(), 'PAYMENT_PROVIDER_ERROR');
        }

        return [
            'id'              => $payment->id,
            'amount'          => $payment->amount,
            'status'          => $payment->status,
            'confirmationUrl' => null,
            'balance'         => Wallet::where('user_id', $user->id)->value('balance'),
        ];
    }
}
