<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\PageComponent;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class DeletePageComponent
{
    /**
     * Delete a page component.
     *
     * @param  mixed  $root
     * @param  array{id: string, license_id: string}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): PageComponent
    {
        $component = PageComponent::where('id', $args['id'])
            ->where('license_id', $args['license_id'])
            ->first();

        if (! $component) {
            throw new GraphQLException('PageComponent not found.', 'VALIDATION');
        }

        $license = $component->license;

        // Verify ownership
        if ($context->user()->id !== $license->user_id) {
            throw new GraphQLException('This action is unauthorized.', 'AUTHORIZATION');
        }

        // Сброс контента не должен стирать ИМЯ блока. Имя и назначение — не контент:
        // тенант подписал блок «Почему нас выбирают», а сбрасывает тексты и картинки,
        // и терять подпись за компанию он не просил. Поэтому строка с именем не
        // удаляется, а обнуляется по данным — снаружи это тот же сброс к дефолтам
        // шаблона (пустой `data` означает «взять всё из шаблона»).
        if ($component->label !== null || $component->role_slug !== null) {
            $component->data = [];
            $component->save();
        } else {
            $component->delete();
        }

        // Invalidate cache for the license (tagged if supported, plain otherwise)
        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException) {
            // database/file cache stores don't support tagging — flush all
            Cache::flush();
        }

        return $component;
    }
}
