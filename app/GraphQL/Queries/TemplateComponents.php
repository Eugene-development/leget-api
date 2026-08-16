<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\Services\TemplateService;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class TemplateComponents
{
    public function __construct(private readonly TemplateService $templateService) {}

    /**
     * Return the list of available component types for a given template and page slug.
     *
     * @param  mixed  $root
     * @param  array{templateId: int, slug: string}  $args
     * @return array<int, array{type: string, label: string}>
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $templateId = (int) $args['templateId'];
        $slug       = $args['slug'];

        if (! $this->templateService->getTemplate($templateId)) {
            throw new GraphQLException(
                "Template {$templateId} not found",
                'TEMPLATE_NOT_FOUND'
            );
        }

        // Именно `getOfferedTypes`, а не `getAllowedTypes`: этот запрос питает
        // список «какой блок добавить», то есть предложение. Выведенные типы из
        // него уходят, но остаются рабочими везде, где уже стоят, —
        // см. config/component_lifecycle.php.
        $types = $this->templateService->getOfferedTypes($templateId, $slug);

        return array_map(
            fn(string $type) => [
                'type'  => $type,
                'label' => $this->labelFor($type),
            ],
            $types
        );
    }

    /**
     * Human-readable label for a component type.
     * Extend this map as new component types are added.
     */
    private function labelFor(string $type): string
    {
        return match ($type) {
            'Hero'          => 'Герой (Hero)',
            'HeroMain'      => 'Главный герой (полноэкранный)',
            'Text'          => 'Текстовый блок',
            'Features'      => 'Преимущества',
            'CTA'           => 'Призыв к действию',
            'Gallery'       => 'Галерея',
            'Testimonials'  => 'Отзывы',
            'ContactForm'   => 'Форма обратной связи',
            'Map'           => 'Карта',
            'LeaderSection' => 'Руководство',
            'Statistics'    => 'Статистика',
            'Message'       => 'О компании',
            'PromoOffer'    => 'Промо-предложение',
            'Equipment'     => 'Комплектация проектов',
            'Stage'         => 'Этапы работы',
            'Incentives'    => 'Преимущества (с галереей)',
            // Назначение «спецпредложения партнёров» ушло в отдельный компонент
            // PartnerOffers (16.08.2026), поэтому ярлык Direction возвращён к тому,
            // чем блок на самом деле является. Сам тип не трогаем ни при каких
            // обстоятельствах: это ключ хранения данных тенанта в
            // upsertPageComponent и якорь артикула 1.1.7.
            'Direction'     => 'Направления',
            'PartnerOffers' => 'Спецпредложения партнёров',
            'Brands'        => 'Бренды и партнёры',
            default         => $type,
        };
    }
}
