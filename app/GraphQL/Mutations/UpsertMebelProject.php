<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\Image;
use App\Models\License;
use App\Models\MebelProject;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpsertMebelProject
{
    /**
     * @param  mixed  $root
     * @param  array{input: array}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): MebelProject
    {
        $input = $args['input'];

        $user = $context->user();
        $license = $user->licenses()->first();

        if (! $license) {
            throw new GraphQLException('No license found for the authenticated user.', 'VALIDATION');
        }

        if (isset($input['id'])) {
            $project = MebelProject::where('id', $input['id'])
                ->where('license_id', $license->id)
                ->first();

            if (! $project) {
                throw new GraphQLException('Mebel project not found or you do not have permission to edit it.', 'VALIDATION');
            }
        } else {
            $project = new MebelProject();
            $project->license_id = $license->id;
            
            // Generate a slug if creating
            $baseSlug = Str::slug($input['value']);
            $project->slug = $baseSlug;
            
            // Ensure slug uniqueness
            $count = 1;
            while (MebelProject::where('slug', $project->slug)->exists()) {
                $project->slug = "{$baseSlug}-{$count}";
                $count++;
            }
        }

        $project->category_id = $input['category_id'];
        $project->value = $input['value'];

        if (array_key_exists('description', $input)) {
            $project->description = $input['description'];
        }
        if (array_key_exists('short_description', $input)) {
            $project->short_description = $input['short_description'];
        }
        if (array_key_exists('price', $input)) {
            $project->price = $input['price'];
        }
        if (array_key_exists('old_price', $input)) {
            $project->old_price = $input['old_price'];
        }
        if (array_key_exists('is_featured', $input)) {
            $project->is_featured = $input['is_featured'];
        }
        if (array_key_exists('is_new', $input)) {
            $project->is_new = $input['is_new'];
        }
        if (array_key_exists('is_active', $input)) {
            $project->is_active = $input['is_active'];
        }

        $project->save();

        // Handle image_urls if provided
        if (isset($input['image_urls'])) {
            // Clear existing images or handle updates. For simplicity, if passed, we can add them.
            // A more robust implementation would diff them. Let's just create new ones and delete old ones if this is an update.
            // Assuming image_urls are the full URLs we want to keep.
            
            // Delete old images
            $project->images()->delete();
            
            // Add new images
            foreach ($input['image_urls'] as $index => $url) {
                $image = new Image();
                $image->url = $url;
                $image->sort_order = $index + 1;
                $image->is_active = true;
                $image->parentable_id = $project->id;
                $image->parentable_type = MebelProject::class;
                $image->save();
            }
        }

        return $project;
    }
}
