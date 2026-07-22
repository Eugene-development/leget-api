<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpsertPageComponent
{
    public function __construct(
        private \App\Services\TemplateService $templateService
    ) {}

    /**
     * Create or update a page component.
     *
     * @param  mixed  $root
     * @param  array{page_id: int|string, license_id: string, type: string, data: mixed}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): PageComponent
    {
        $license = License::find($args['license_id']);

        if (! $license) {
            throw new GraphQLException('License not found.', 'VALIDATION');
        }

        // Verify ownership
        if ($context->user()->id !== $license->user_id) {
            throw new GraphQLException('This action is unauthorized.', 'AUTHORIZATION');
        }

        $pageId = (string) $args['page_id'];
        $page = null;

        if (str_starts_with($pageId, 'slug:')) {
            $slug = substr($pageId, 5);
            $page = Page::firstOrCreate(
                [
                    'license_id' => $license->id,
                    'slug'       => $slug,
                ]
            );
        } else {
            $page = Page::where('id', $pageId)
                ->where('license_id', $license->id)
                ->first();
        }

        if (! $page) {
            throw new GraphQLException('Page not found.', 'VALIDATION');
        }

        $component = PageComponent::where('page_id', $page->id)
            ->where('type', $args['type'])
            ->first();

        if (! $component) {
            $component = new PageComponent([
                'page_id' => $page->id,
                'type'    => $args['type'],
            ]);
            // Присваиваем sort_order из порядка компонентов в config/templates.php,
            // чтобы новосохранённый блок занял своё место в шаблоне, а не всплыл
            // наверх со значением по умолчанию (0). Для динамических/нестандартных
            // страниц (тип не найден в определениях) — дописываем в конец.
            $component->sort_order = $this->resolveSortOrder(
                (int) $license->template_id,
                $page,
                $args['type'],
            );
        }

        $component->data = $args['data'];
        $component->license_id = $license->id;
        $component->is_active = true;
        $component->save();

        // Invalidate cache for the license (tagged if supported, plain otherwise)
        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException) {
            // database/file cache stores don't support tagging — flush all
            Cache::flush();
        }

        return $component;
    }

    /**
     * Определить sort_order для нового компонента по его позиции в config/templates.php.
     * Если тип не описан в шаблоне для этого slug (динамические/кастомные страницы) —
     * возвращаем max(sort_order)+1, чтобы дописать блок в конец, а не наверх.
     */
    private function resolveSortOrder(int $templateId, Page $page, string $type): int
    {
        $types = $this->templateService->getAllowedTypes($templateId, (string) $page->slug);
        $index = array_search($type, $types, true);

        if ($index !== false) {
            return (int) $index;
        }

        return (int) (PageComponent::where('page_id', $page->id)->max('sort_order') ?? -1) + 1;
    }
}
