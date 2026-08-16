<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Services\TemplateService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Имя и назначение блока на конкретном сайте.
 *
 * Завершает расслоение имени. Каталог глобален и знает только конструкцию
 * (`component_variants.morph`) и то, что она способна исполнить
 * (`component_variant_role`); смысл появляется здесь, у конкретного тенанта:
 *
 *   label     — как блок подписан в интерфейсе ЭТОГО сайта
 *   roleSlug  — чем блок работает: «Преимущества», «Услуги», «Этапы»
 *
 * Координаты те же, что у `upsertPageComponent` (page + license + type), а не `id`:
 * переименовать можно и блок, который тенант ещё ни разу не правил, — строки
 * `page_components` у него нет, и мутация её заводит.
 *
 * Роль НЕ меняет форму данных: `data` принадлежит конструкции, а не назначению.
 * Поэтому мутация не трогает `data` вовсе — иначе смена роли теряла бы контент,
 * и полиморфизм превратился бы в скрытое наследование.
 *
 * Модель целиком: docs/architecture/component-morphotypes.md
 */
final class RenamePageComponent
{
    /**
     * Предел длины имени. Колонка — обычный `string` (255), но в панели настроек
     * имя стоит в одну строку заголовка, и 120 символов туда уже не помещаются.
     * Режем на входе, а не многоточием в вёрстке: тенант должен видеть, что
     * сохранилось не всё, в момент сохранения, а не потом.
     */
    private const LABEL_MAX = 120;

    public function __construct(
        private TemplateService $templateService
    ) {}

    /**
     * @param  mixed  $root
     * @param  array{page_id: int|string, license_id: string, type: string, label?: string|null, role_slug?: string|null}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): PageComponent
    {
        $license = License::find($args['license_id']);

        if (! $license) {
            throw new GraphQLException('License not found.', 'VALIDATION');
        }

        if ($context->user()->id !== $license->user_id) {
            throw new GraphQLException('This action is unauthorized.', 'AUTHORIZATION');
        }

        $page = $this->resolvePage($license, (string) $args['page_id']);

        if (! $page) {
            throw new GraphQLException('Page not found.', 'VALIDATION');
        }

        $label    = $this->normalizeLabel($args['label'] ?? null);
        $roleSlug = $this->normalizeRole($args['role_slug'] ?? null);

        $component = PageComponent::where('page_id', $page->id)
            ->where('type', $args['type'])
            ->first();

        if (! $component) {
            // Блок ни разу не правили — строки нет. Заводим её с ПУСТЫМИ данными:
            // тенант дал блоку имя, а не контент, и подставлять сюда дефолты шаблона
            // нельзя — они и так подмешиваются при рендере, а осевшая копия
            // разошлась бы с шаблоном при первом же его изменении.
            $component = new PageComponent([
                'page_id' => $page->id,
                'type'    => $args['type'],
                'data'    => [],
            ]);
            $component->sort_order = $this->resolveSortOrder(
                (int) $license->template_id,
                $page,
                (string) $args['type'],
            );
            $component->license_id = $license->id;
            $component->is_active  = true;
        }

        // Оба поля приводятся к переданному, включая null: пустая строка от клиента
        // означает «убрать имя», и блок возвращается к каскаду
        // label ?? имя роли ?? морфотип. Это осмысленная операция, а не пропуск
        // аргумента, поэтому «не передано» и «передано пустым» здесь не различаются.
        $component->label     = $label;
        $component->role_slug = $roleSlug;
        $component->save();

        // Имя приезжает клиенту через renderPage, а он кэшируется на час —
        // без сброса тенант увидел бы старую подпись до истечения TTL.
        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException) {
            // database/file cache stores don't support tagging — flush all
            Cache::flush();
        }

        return $component;
    }

    /**
     * Страница по id или по `slug:<slug>` — та же схема, что в upsertPageComponent:
     * у блока на ещё не сохранённой странице реального id нет.
     */
    private function resolvePage(License $license, string $pageId): ?Page
    {
        if (str_starts_with($pageId, 'slug:')) {
            return Page::firstOrCreate([
                'license_id' => $license->id,
                'slug'       => substr($pageId, 5),
            ]);
        }

        return Page::where('id', $pageId)
            ->where('license_id', $license->id)
            ->first();
    }

    /**
     * Пустое имя — это null, а не пустая строка: иначе каскад подписи упёрся бы
     * в `''` и блок остался бы без имени вовсе вместо отката к роли и морфотипу.
     */
    private function normalizeLabel(?string $label): ?string
    {
        $trimmed = trim((string) $label);

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, self::LABEL_MAX);
    }

    /**
     * Роль проверяется по справочнику, потому что внешнего ключа на него нет:
     * он живёт в config/component_roles.php, а не в таблице (обоснование — в шапке
     * миграции create_component_variant_role_table). Эта проверка и есть
     * единственная защита от того, чтобы в `role_slug` осел мусор.
     *
     * Выведенные роли (`retired`) отвергаются: их нельзя ВЫБРАТЬ заново. Уже
     * выбранные у тенантов при этом продолжают жить — их никто не переписывает.
     */
    private function normalizeRole(?string $slug): ?string
    {
        $trimmed = trim((string) $slug);

        if ($trimmed === '') {
            return null;
        }

        $known   = config('component_roles.roles', []);
        $retired = config('component_roles.retired', []);

        if (! isset($known[$trimmed]) || in_array($trimmed, $retired, true)) {
            throw new GraphQLException("Unknown component role: {$trimmed}", 'VALIDATION');
        }

        return $trimmed;
    }

    /**
     * Позиция блока в шаблоне — на случай, если строку заводит именно эта мутация.
     * Копия логики upsertPageComponent: без неё переименованный, но не правленый
     * блок всплыл бы наверх страницы со `sort_order = 0`.
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
