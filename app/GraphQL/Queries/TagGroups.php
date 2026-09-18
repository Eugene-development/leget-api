<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\License;
use App\Models\TagGroup;
use App\Services\BrandTags;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class TagGroups
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $request = $context->request();
        $license = License::where('domain', $request->header('X-Forwarded-Host') ?? $request->getHost())
            ->where('is_active', true)->where('status', '!=', 'suspended')->first();
        $groups = TagGroup::orderBy('sort_order')->with(['tags' => fn ($q) => $q
            ->where(fn ($q) => $q->whereNull('license_id')->when($license, fn ($q) => $q->orWhere('license_id', $license->id)))])
            ->when(isset($args['rubric']), fn ($q) => $q->whereIn('id', DB::table('tag_group_rubric')->select('tag_group_id')->where('rubric_slug', $args['rubric'])))->get();
        $presented = app(BrandTags::class)->present($groups->flatMap->tags, $license)->keyBy('id');

        return $groups->map(fn ($group) => [...$group->only(['id', 'name', 'slug']),
            'tags' => $group->tags->map(fn ($tag) => $presented->get($tag->id))
                ->filter(fn ($tag) => $tag && (! $tag['managed'] || $tag['href']))->values()->all(),
        ])->all();
    }
}
