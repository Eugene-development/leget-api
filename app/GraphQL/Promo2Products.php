<?php

declare(strict_types=1);

namespace App\GraphQL;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Services\Promo2Products as Products;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class Promo2Products
{
    public function listing(mixed $root, array $args, GraphQLContext $context): array
    {
        return app(Products::class)->items($this->license($args, $context), $args['kind'] ?? 'kitchen', true);
    }

    public function save(mixed $root, array $args, GraphQLContext $context): array
    {
        return app(Products::class)->save($this->license($args, $context), $args['input'], $args['kind'] ?? 'kitchen');
    }

    public function delete(mixed $root, array $args, GraphQLContext $context): string
    {
        return app(Products::class)->delete($this->license($args, $context), $args['id'], $args['kind'] ?? 'kitchen');
    }

    private function license(array $args, GraphQLContext $context): License
    {
        $license = License::find($args['licenseId']);
        if (! $context->user() || ! $license || (string) $license->user_id !== (string) $context->user()->id
            || (int) $license->template_id !== 2) {
            throw new GraphQLException('Управлять товарами может только владелец этого сайта Promo-2.', 'AUTHORIZATION');
        }

        return $license;
    }
}
