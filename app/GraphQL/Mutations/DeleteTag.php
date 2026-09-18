<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\Tag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class DeleteTag
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Tag
    {
        if (! $context->user()?->licenses()->exists()) {
            throw new GraphQLException('Удалять теги может только владелец сайта.', 'FORBIDDEN');
        }

        Validator::make($args, ['id' => ['required', 'ulid', 'exists:tags,id']])->validate();
        $tag = Tag::findOrFail($args['id']);
        // FK cascade removes all polymorphic links, including soft-deleted projects.
        $tag->delete();

        Cache::forever('catalog-tags:revision', (string) Str::uuid());

        return $tag;
    }
}
