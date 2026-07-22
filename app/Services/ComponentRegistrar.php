<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Component;
use App\Models\ComponentVariant;
use App\Models\TemplatePage;
use Illuminate\Support\Facades\DB;

/**
 * Регистрация компонентов глобального каталога с авто-присвоением артикула.
 *
 * Артикул: {template_id}.{page_number}.{component_number}.{version}
 * где version — версия компонента (v1…v4), НЕ цветовая схема.
 *
 * Нумерация — по шаблону max(...)+1 в транзакции (как sort_order в UpsertMebelProject).
 * Идемпотентно: повторная регистрация существующих сущностей не создаёт дублей и
 * не перенумеровывает. Артикул иммутабелен (после присвоения не меняется).
 *
 * Гонки при max()+1 защищены unique-индексами (включая unique(article));
 * при коллизии выбрасывается QueryException — вызывающая сторона может повторить.
 */
class ComponentRegistrar
{
    /**
     * Вернуть или создать страницу шаблона с авто-присвоением page_number.
     */
    public function ensurePage(int $templateId, string $slug, ?string $name = null): TemplatePage
    {
        return DB::transaction(function () use ($templateId, $slug, $name): TemplatePage {
            $page = TemplatePage::where('template_id', $templateId)
                ->where('slug', $slug)
                ->first();

            if ($page) {
                if ($name !== null && $page->name === null) {
                    $page->name = $name;
                    $page->save();
                }
                return $page;
            }

            $pageNumber = (int) (TemplatePage::where('template_id', $templateId)->max('page_number') ?? 0) + 1;

            return TemplatePage::create([
                'template_id' => $templateId,
                'slug'        => $slug,
                'page_number' => $pageNumber,
                'name'        => $name,
            ]);
        });
    }

    /**
     * Вернуть или создать компонент с авто-присвоением component_number.
     */
    public function ensureComponent(int $templateId, string $slug, string $type, ?string $name = null): Component
    {
        return DB::transaction(function () use ($templateId, $slug, $type, $name): Component {
            $page = $this->ensurePage($templateId, $slug);

            $component = Component::where('template_id', $templateId)
                ->where('page_id', $page->id)
                ->where('type', $type)
                ->first();

            if ($component) {
                if ($name !== null && $component->name === null) {
                    $component->name = $name;
                    $component->save();
                }
                return $component;
            }

            $componentNumber = (int) (Component::where('template_id', $templateId)
                ->where('page_id', $page->id)
                ->max('component_number') ?? 0) + 1;

            $component = Component::create([
                'template_id'       => $templateId,
                'page_id'           => $page->id,
                'type'              => $type,
                'component_number'  => $componentNumber,
                'name'              => $name,
            ]);

            // Делаем страницу доступной без повторной загрузки (для построения артикула).
            $component->setRelation('page', $page);

            return $component;
        });
    }

    /**
     * Вернуть или создать версию компонента с авто-присвоенным артикулом.
     */
    public function ensureVariant(Component $component, int $version, ?string $name = null): ComponentVariant
    {
        return DB::transaction(function () use ($component, $version, $name): ComponentVariant {
            $variant = ComponentVariant::where('component_id', $component->id)
                ->where('version', $version)
                ->first();

            if ($variant) {
                return $variant;
            }

            return ComponentVariant::create([
                'component_id' => $component->id,
                'version'      => $version,
                'name'         => $name,
                'article'      => $this->buildArticle($component, $version),
            ]);
        });
    }

    /**
     * Построить полный артикул «1.8.12.4» по известным значениям.
     */
    private function buildArticle(Component $component, int $version): string
    {
        $pageNumber = $component->page?->page_number
            ?? TemplatePage::where('id', $component->page_id)->value('page_number');

        return "{$component->template_id}.{$pageNumber}.{$component->component_number}.{$version}";
    }
}
