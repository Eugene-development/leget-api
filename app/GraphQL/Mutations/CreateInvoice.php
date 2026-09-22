<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\Models\Wallet;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class CreateInvoice
{
    /**
     * Создаёт счёт на оплату через расчётный счёт.
     *
     * @param  mixed  $root
     * @param  array{amount: string, companyName: string, inn: ?string}  $args
     * @return array{success: bool, invoice: Invoice}
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $user = $context->user();
        $wallet = Wallet::forUser($user->id);

        // Номер присваивается внутри с повтором при коллизии UNIQUE-индекса
        $invoice = Invoice::createWithUniqueNumber([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'amount' => $args['amount'],
            'status' => 'pending',
            'company_name' => trim($args['companyName']),
            'inn' => isset($args['inn']) && $args['inn'] !== '' ? $args['inn'] : null,
        ]);

        return [
            'success' => true,
            'invoice' => $invoice,
        ];
    }
}
