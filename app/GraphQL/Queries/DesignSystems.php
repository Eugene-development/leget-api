<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\DesignSystem;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Список дизайн-систем, опционально относящихся к шаблону.
 *
 * Фильтр по шаблону отвечает не только по заявленной области. У Базовой системы
 * заявленной области нет вовсе — она одна на платформу и действует всюду, где ещё
 * остались неприписанные версии. Поэтому для шаблона возвращаются:
 *
 *   1) системы, объявленные для этого шаблона в design_system_template;
 *   2) Базовая — если в ней ещё лежат версии компонентов этого шаблона.
 *
 * Второй пункт и даёт естественное затухание: когда все версии шаблона
 * отрефакторены и разъехались по настоящим системам, Базовая перестаёт
 * предлагаться на нём сама, без отдельного действия.
 *
 * ВНИМАНИЕ: это ещё не проверка инварианта покрытия. Здесь возвращается то, что
 * относится к шаблону, а не то, что готово к выбору пользователем. Готовность —
 * поле isPublished на паре «система × шаблон», и проверка покрытия при его
 * выставлении пока не реализована.
 */
final class DesignSystems
{
    /**
     * @param  mixed  $root
     * @param  array{template_id?: int}  $args
     *
     * @return \Illuminate\Support\Collection<int, DesignSystem>
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): Collection
    {
        if (! isset($args['template_id'])) {
            return DesignSystem::orderByDesc('is_base')->orderBy('name')->get();
        }

        $templateId = (int) $args['template_id'];

        $declaredIds = DB::table(DesignSystem::TEMPLATE_PIVOT)
            ->where('template_id', $templateId)
            ->pluck('design_system_id');

        $ids = $declaredIds->all();

        $base = DesignSystem::query()->base()->first();

        if ($base && $this->baseHoldsVariantsOfTemplate($base, $templateId)) {
            $ids[] = $base->id;
        }

        if ($ids === []) {
            return new Collection();
        }

        // Базовая первой: пользователь, сидящий на ней, должен видеть, где он находится.
        return DesignSystem::whereIn('id', array_unique($ids))
            ->orderByDesc('is_base')
            ->orderBy('name')
            ->get();
    }

    /**
     * Остались ли в Базовой версии компонентов этого шаблона.
     *
     * Шаблон у версии известен через её компонент (components.template_id) —
     * отдельной привязки Базовой к шаблонам не требуется.
     */
    private function baseHoldsVariantsOfTemplate(DesignSystem $base, int $templateId): bool
    {
        return DB::table('component_variant_design_system as p')
            ->join('component_variants as v', 'v.id', '=', 'p.component_variant_id')
            ->join('components as c', 'c.id', '=', 'v.component_id')
            ->where('p.design_system_id', $base->id)
            ->where('c.template_id', $templateId)
            ->exists();
    }
}
