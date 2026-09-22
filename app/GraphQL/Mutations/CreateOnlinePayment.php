<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Services\PaymentService;
use App\Services\YooKassaService;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use RuntimeException;

final class CreateOnlinePayment
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly YooKassaService $yooKassa,
    ) {}

    /**
     * Создаёт онлайн-платёж и возвращает ссылку на страницу оплаты ЮKassa.
     *
     * Баланс здесь не меняется: деньги зачисляются только после подтверждения
     * оплаты провайдером (webhook или syncOnlinePayment при возврате в ЛК).
     *
     * @param  mixed  $root
     * @param  array{amount: string}  $args
     * @return array<string, mixed>
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        if (! $this->yooKassa->isConfigured()) {
            throw new GraphQLException(
                'Онлайн-оплата временно недоступна. Воспользуйтесь оплатой по расчётному счёту.',
                'PAYMENT_PROVIDER_NOT_CONFIGURED'
            );
        }

        try {
            $result = $this->payments->start($context->user(), $args['amount']);
        } catch (RuntimeException $e) {
            throw new GraphQLException($e->getMessage(), 'PAYMENT_PROVIDER_ERROR');
        }

        $payment = $result['payment'];

        if ($payment->status === 'test') {
            throw new GraphQLException('Подключён тестовый магазин. Рабочий баланс не пополняется.', 'PAYMENT_PROVIDER_ERROR');
        }

        if ($result['confirmation_url'] === null) {
            throw new GraphQLException(
                'Платёжный сервис не вернул ссылку на оплату. Попробуйте позже.',
                'PAYMENT_PROVIDER_ERROR'
            );
        }

        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'status' => $payment->status,
            'confirmationUrl' => $result['confirmation_url'],
            'balance' => null,
        ];
    }
}
