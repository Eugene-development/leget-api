<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Page;
use App\Services\TemplateService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class MovePageComponent
{
    public function __construct(private TemplateService $templates) {}

    /** Atomically move one block relative to the latest saved order. */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $order = DB::transaction(function () use ($args, $context) {
            // Also serializes first writes to virtual pages on this site.
            $license = License::whereKey($args['license_id'])->lockForUpdate()->first();
            if (! $license || ! $context->user() || (string) $context->user()->id !== (string) $license->user_id) {
                throw new GraphQLException('Нет доступа к изменению этого сайта.', 'AUTHORIZATION');
            }
            if (! in_array($args['direction'], ['up', 'down'], true)) {
                throw new GraphQLException('Неизвестное направление перемещения.', 'VALIDATION');
            }

            $pageId = (string) $args['page_id'];
            $query = Page::where('license_id', $license->id);
            if (str_starts_with($pageId, 'slug:')) {
                $slug = substr($pageId, 5);
                $page = $query->where('slug', $slug)->lockForUpdate()->first()
                    ?? new Page(['license_id' => $license->id, 'slug' => $slug]);
            } else {
                $page = $query->whereKey($pageId)->lockForUpdate()->first();
            }
            if (! $page || ! str_starts_with($page->slug, '/')) {
                throw new GraphQLException('Страница не найдена.', 'VALIDATION');
            }

            $templateSlug = $this->templates->resolveTemplateSlug((int) $license->template_id, $page->slug);
            $components = $this->templates->getMergedPageComponents($license->id, $page, $templateSlug);
            $order = $components->filter(fn ($component) => $component->is_active && $component->type !== 'Footer')
                ->pluck('type')->values()->all();
            $index = array_search($args['type'], $order, true);
            if ($index === false) {
                throw new GraphQLException('Блок не найден на этой странице.', 'VALIDATION');
            }
            $target = $index + ($args['direction'] === 'up' ? -1 : 1);
            if ($target >= 0 && $target < count($order)) {
                [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
                $page->component_order = $order;
                $page->save();
            }

            return $order;
        });

        try {
            Cache::tags(["license:{$args['license_id']}"])->flush();
        } catch (\BadMethodCallException) {
            Cache::flush();
        }

        return $order;
    }
}
