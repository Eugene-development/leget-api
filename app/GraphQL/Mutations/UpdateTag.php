<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\Tag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpdateTag
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Tag
    {
        if (! $context->user()?->licenses()->exists()) {
            throw new GraphQLException('Изменять теги может только владелец сайта.', 'FORBIDDEN');
        }

        $input = $args['input'];
        $input['name'] = Str::squish($input['name'] ?? '');
        Validator::make($input, [
            'id' => ['required', 'ulid', 'exists:tags,id'],
            'name' => ['required', 'string', 'max:120'],
        ])->validate();
        $tag = Tag::findOrFail($input['id']);
        if ($tag->target_type) {
            throw new GraphQLException('Этот тег управляется через страницу бренда.', 'MANAGED_TAG');
        }
        $tag->name = $input['name'];
        $tag->normalized_name = Str::lower($input['name']);
        try {
            $tag->save();
        } catch (UniqueConstraintViolationException) {
            throw new GraphQLException('Тег с таким названием уже есть в этой группе.', 'TAG_NAME_TAKEN');
        }

        Cache::forever('catalog-tags:revision', (string) Str::uuid());

        return $tag;
    }
}
