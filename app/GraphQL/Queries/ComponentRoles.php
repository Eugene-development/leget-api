<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Справочник ролей блока — для списка выбора в интерфейсе.
 *
 * Читает config/component_roles.php, а не БД: справочника в БД намеренно нет,
 * состав ролей выкатывается вместе с релизом (обоснование в шапке миграции
 * create_component_variant_role_table).
 *
 * Выведенные роли (`retired`) из выдачи исключаются: их нельзя предлагать новым
 * блокам. Но удалять их из конфига нельзя — у тенантов такая роль может быть уже
 * выбрана, и `page_components.role_slug` останется висеть на несуществующий ключ.
 * Поэтому именно фильтр на выдаче, а не удаление строки.
 */
final class ComponentRoles
{
    /**
     * @param  mixed  $root
     * @param  array{group?: string}  $args
     *
     * @return list<array{slug: string, name: string, group: string, groupName: string, description: string|null}>
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $groups  = config('component_roles.groups', []);
        $roles   = config('component_roles.roles', []);
        $retired = config('component_roles.retired', []);

        $out = [];

        foreach ($roles as $slug => $role) {
            if (in_array($slug, $retired, true)) {
                continue;
            }

            if (isset($args['group']) && ($role['group'] ?? null) !== $args['group']) {
                continue;
            }

            $out[] = [
                'slug'        => (string) $slug,
                'name'        => (string) ($role['name'] ?? $slug),
                'group'       => (string) ($role['group'] ?? ''),
                // Имя группы отдаём вместе со строкой, чтобы фронт не тянул
                // второй запрос ради заголовков в списке выбора.
                'groupName'   => (string) ($groups[$role['group'] ?? ''] ?? ''),
                'description' => $role['description'] ?? null,
            ];
        }

        return $out;
    }
}
