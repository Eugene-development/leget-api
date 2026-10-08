<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\MebelProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class SetSharedProjectVisibility
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        Validator::make($args, [
            'id' => ['required', 'string', 'ulid'],
            'licenseId' => ['required', 'string', 'ulid'],
            'isVisible' => ['required', 'boolean'],
        ])->validate();

        $user = $context->user();
        if (! $user) {
            throw new GraphQLException('Войдите в аккаунт владельца сайта.', 'AUTHORIZATION');
        }

        return DB::transaction(function () use ($args, $user) {
            // Serialize changes to the JSON so category and project settings survive concurrent edits.
            $license = License::whereKey($args['licenseId'])->lockForUpdate()->first();
            if (! $license || (string) $license->user_id !== (string) $user->id) {
                throw new GraphQLException('Недостаточно прав для изменения сайта.', 'AUTHORIZATION');
            }

            $project = MebelProject::whereKey($args['id'])->whereNull('license_id')->active()
                ->whereHas('category', fn ($q) => $q->where('is_active', true)
                    ->whereHas('rubric', fn ($q) => $q->where('slug', 'mebel')->where('is_active', true)))
                ->first();
            if (! $project) {
                throw new GraphQLException('Общий проект не найден.', 'VALIDATION');
            }

            $settings = $license->catalog_settings ?? [];
            if ($args['isVisible']) {
                unset($settings['projects'][$project->id]);
            } else {
                $settings['projects'][$project->id] = false;
            }
            $license->catalog_settings = $settings;
            $license->save();

            // Render and search cache keys include the persisted settings fingerprint.
            return ['id' => $project->id, 'isVisible' => (bool) $args['isVisible']];
        });
    }
}
