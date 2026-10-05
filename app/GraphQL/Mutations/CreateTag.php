<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\Tag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class CreateTag
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Tag
    {
        if (! $context->user()?->licenses()->exists()) {
            throw new GraphQLException('Добавлять теги может только владелец сайта.', 'FORBIDDEN');
        }

        $input = $args['input'];
        $input['name'] = Str::squish($input['name'] ?? '');
        Validator::make($input, [
            'tag_group_id' => ['required', 'integer', 'exists:tag_groups,id'],
            'name' => ['required', 'string', 'max:120'],
        ])->validate();

        if (\App\Models\TagGroup::whereKey($input['tag_group_id'])->whereIn('slug', ['appliance-brand', 'plumbing-brand', 'countertop-brand', 'door-brand', 'window-brand'])->exists()) {
            throw new GraphQLException('Создайте бренд в соответствующей рубрике — тег появится автоматически.', 'BRAND_TAG_AUTOMATIC');
        }

        // Уникальный индекс также защищает от двух одновременных запросов.
        $tag = Tag::whereNull('target_type')->firstOrCreate([
            'tag_group_id' => $input['tag_group_id'],
            'normalized_name' => Str::lower($input['name']),
        ], ['name' => $input['name']]);
        Cache::forever('catalog-tags:revision', (string) Str::uuid());

        return $tag;
    }
}
