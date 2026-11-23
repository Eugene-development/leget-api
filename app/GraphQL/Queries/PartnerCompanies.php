<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Services\PartnerDirectory;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class PartnerCompanies
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $request = $context->request();
        $license = License::where('domain', $request->header('X-Forwarded-Host') ?? $request->getHost())->first();
        if (! $license || ! $license->is_active || $license->status === 'suspended' || (int) $license->template_id !== 1) {
            throw new GraphQLException('Page not found', 'PAGE_NOT_FOUND');
        }

        $directory = app(PartnerDirectory::class);
        $rows = $directory->published()
            ->when(isset($args['type']), fn ($query) => $query->where('partner_type', $args['type']))
            ->orderBy('directory_name')->orderBy('id')
            ->paginate($args['perPage'] ?? PartnerDirectory::PAGE_SIZE, page: $args['page'] ?? 1);

        return [
            'items' => $rows->getCollection()->map($directory->summary(...))->all(),
            'page' => $rows->currentPage(),
            'lastPage' => $rows->lastPage(),
        ];
    }
}
