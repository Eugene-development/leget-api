<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\Category;
use App\Models\License;
use App\Services\ApplianceBrands;
use App\Services\CatalogVisibility;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class ToggleCategory
{
    /**
     * Toggle a site override, not the shared category's default.
     *
     * @param  mixed  $root
     * @param  array{id: string, license_id: string, is_enabled: bool}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): Category
    {
        $user = $context->user();
        $license = License::find($args['license_id']);

        if (! $user || ! $license || (string) $user->id !== (string) $license->user_id) {
            throw new GraphQLException('This action is unauthorized.', 'AUTHORIZATION');
        }

        $category = Category::whereKey($args['id'])
            ->where('is_active', true)
            ->whereHas('rubric', fn ($q) => $q->where('is_active', true)
                ->whereIn('slug', array_column(CatalogVisibility::SIDEBARS, 0)))
            ->first();

        if (! $category || isset(ApplianceBrands::RUBRICS[$category->rubric?->slug ?? ''])) {
            $category = null;
            foreach (array_keys(ApplianceBrands::RUBRICS) as $rubric) {
                $category = app(ApplianceBrands::class)->entries($license, $rubric)->firstWhere('id', $args['id']);
                if ($category) {
                    break;
                }
            }
        }

        if (! $category) {
            throw new GraphQLException('Category not found.', 'VALIDATION');
        }

        DB::transaction(function () use ($license, $category, $args, $user) {
            // Concurrent toggles of different entries must not overwrite each other.
            $locked = License::whereKey($license->id)->lockForUpdate()->firstOrFail();
            if ((string) $locked->user_id !== (string) $user->id) {
                throw new GraphQLException('This action is unauthorized.', 'AUTHORIZATION');
            }
            $settings = $locked->catalog_settings ?? [];
            $settings['categories'][$category->id] = (bool) $args['is_enabled'];
            $locked->catalog_settings = $settings;
            $locked->save();
        });

        // RenderPage keys include the persisted settings fingerprint. No global
        // cache flush, even with file/database stores or concurrent renders.
        $category->is_enabled = (bool) $args['is_enabled'];

        return $category;
    }
}
