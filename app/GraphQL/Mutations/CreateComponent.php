<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Component;
use App\Services\ComponentRegistrar;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Зарегистрировать компонент в каталоге с авто-присвоением артикула.
 *
 * Номера (page_number / component_number) и артикулы вариантов присваиваются
 * сервисом ComponentRegistrar. Идемпотентно: повторный вызов с теми же
 * (template, slug, type) вернёт существующий компонент без перенумерации.
 */
final class CreateComponent
{
    public function __construct(private readonly ComponentRegistrar $registrar) {}

    /**
     * @param  mixed  $root
     * @param  array{template_id: int, slug: string, type: string, name?: string, variants?: list<int>}  $args
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): Component
    {
        $component = $this->registrar->ensureComponent(
            (int) $args['template_id'],
            $args['slug'],
            $args['type'],
            $args['name'] ?? null,
        );

        $versions = $args['variants'] ?? [1];
        foreach ($versions as $version) {
            $this->registrar->ensureVariant($component, (int) $version);
        }

        return $component->load(['page', 'variants']);
    }
}
