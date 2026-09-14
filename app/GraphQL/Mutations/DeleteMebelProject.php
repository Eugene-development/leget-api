<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\MebelProject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class DeleteMebelProject
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): MebelProject
    {
        $user = $context->user();
        if (! $user) {
            throw new GraphQLException('Для удаления проекта войдите в аккаунт владельца.', 'AUTHORIZATION');
        }
        Validator::make($args, ['id' => ['required', 'string', 'ulid']])->validate();

        $project = DB::transaction(function () use ($args, $user) {
            // A retry after a lost response must not delete anything else or fail on an already deleted row.
            $project = MebelProject::withTrashed()
                ->whereIn('license_id', $user->licenses()->select('id'))
                ->whereKey($args['id'])
                ->lockForUpdate()
                ->first();

            if (! $project) {
                throw new GraphQLException('Проект не найден или недоступен для удаления.', 'AUTHORIZATION');
            }
            if (! $project->trashed()) {
                $project->delete();
            }

            return $project;
        });

        try {
            Cache::tags(["license:{$project->license_id}"])->flush();
        } catch (\BadMethodCallException) {
            Cache::flush();
        }

        return $project;
    }
}
