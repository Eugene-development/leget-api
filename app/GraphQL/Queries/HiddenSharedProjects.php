<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\MebelProject;
use App\Services\CatalogVisibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class HiddenSharedProjects
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Collection
    {
        Validator::make($args, [
            'licenseId' => ['required', 'string', 'ulid'],
            'categoryId' => ['required', 'string', 'ulid'],
        ])->validate();
        $user = $context->user();
        $license = License::find($args['licenseId']);
        if (! $user || ! $license || (string) $license->user_id !== (string) $user->id) {
            throw new GraphQLException('Недостаточно прав для просмотра скрытых проектов.', 'AUTHORIZATION');
        }

        return MebelProject::whereNull('license_id')->active()
            ->whereIn('id', app(CatalogVisibility::class)->hiddenProjectIds($license))
            ->where('category_id', $args['categoryId'])
            ->whereHas('category', fn ($q) => $q->where('is_active', true)
                ->whereHas('rubric', fn ($q) => $q->where('slug', 'mebel')->where('is_active', true)))
            ->orderBy('value')->get();
    }
}
