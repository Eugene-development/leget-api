<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\ApplianceBrand;
use App\Models\License;
use App\Services\ApplianceBrands;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class DeleteApplianceBrand
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ApplianceBrand
    {
        $directory = app(ApplianceBrands::class);
        $rubric = $args['rubric'] ?? 'bytovaya-tehnika';
        $directory->definition($rubric);
        $license = $directory->authorize($args['licenseId'], $context->user());
        Validator::make($args, ['id' => ['required', 'ulid']])->validate();

        return DB::transaction(function () use ($directory, $license, $args, $context, $rubric) {
            $locked = License::whereKey($license->id)->lockForUpdate()->firstOrFail();
            $directory->authorize($locked->id, $context->user());
            $brand = $directory->editable($locked, $args['id'], $rubric);
            $brand->save();
            $brand->delete();
            $directory->changed($locked);

            return $brand;
        });
    }
}
