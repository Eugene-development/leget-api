<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Component;
use App\Models\ComponentVariant;
use App\Models\DesignSystem;
use App\Models\TemplatePage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
    public function ensureComponent(
        int $templateId,
        string $slug,
        string $type,
        ?string $name = null,
        ?bool $isActive = null,
    ): Component {
        return DB::transaction(function () use ($templateId, $slug, $type, $name, $isActive): Component {
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

                // Признак вывода приводится к переданному на КАЖДОМ прогоне, в отличие
                // от имени: для сеятеля `config/component_lifecycle.php` — источник
                // истины, а не начальное значение. Иначе вывод компонента действовал бы
                // только на тех установках, где каталог сеяли впервые после правки
                // конфига, а откат вывода не действовал бы вообще.
                //
                // `null` означает «не трогать», и это не мелочь. Регистратор вызывает
                // не только сеятель: мутация `CreateComponent` о конфиге вывода не знает
                // и статуса не передаёт. С безусловным приведением она молча возвращала
                // бы выведенный компонент в обращение — конфиг говорил бы одно, БД
                // другое, и разошлись бы они до следующего сеяния.
                if ($isActive !== null && $component->is_active !== $isActive) {
                    $component->is_active = $isActive;
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
                'is_active'         => $isActive ?? true,
            ]);

            // Делаем страницу доступной без повторной загрузки (для построения артикула).
            $component->setRelation('page', $page);

            return $component;
        });
    }

    /**
     * Вернуть или создать версию компонента с авто-присвоенным артикулом.
     *
     * Новая версия попадает в Базовую дизайн-систему — песочницу для того, что ещё
     * не приписано к настоящей системе. Без этого созданный вариант провалился бы
     * между песочницей и продуктом: не принадлежал бы ни одной системе и не попал бы
     * ни в один переключатель.
     *
     * Статус при СОЗДАНИИ — active, хотя в БД у колонки default draft. Это не
     * рассогласование: регистратор вызывается для версий, которые уже существуют
     * в коде и работают (seed каталога, регистрация нового блока). Default в схеме
     * защищает прямые вставки мимо регистратора. Действительно невыпущенную версию
     * заводите с явным STATUS_DRAFT.
     *
     * `$status = null` — «не трогать существующую версию». Явный статус приводит её
     * к переданному и служит механикой вывода из обращения: `component-catalog:seed`
     * передаёт сюда значение из `config/component_lifecycle.php`. Разница
     * принципиальна — вызывающие, которые о выводе не знают (`CreateComponent`),
     * обязаны оставлять статус как есть, иначе снимут вывод молча.
     */
    public function ensureVariant(
        Component $component,
        int $version,
        ?string $name = null,
        ?string $status = null,
    ): ComponentVariant {
        return DB::transaction(function () use ($component, $version, $name, $status): ComponentVariant {
            $variant = ComponentVariant::where('component_id', $component->id)
                ->where('version', $version)
                ->first();

            if ($variant) {
                // Самолечение для версий, созданных до появления дизайн-систем:
                // если версия не принадлежит НИ ОДНОЙ системе, она осиротела — вернём
                // её в песочницу. Проверка «ни одной» здесь обязательна: версию,
                // уже приписанную к настоящей системе, класть обратно в Базовую нельзя,
                // членство в Базовой исключающее.
                $this->attachToBaseIfOrphaned($variant);

                // Статус приводится к переданному, иначе вывод версии из обращения
                // не действовал бы ни на одну существующую версию — а выводят
                // всегда существующие. Ровно этой строки не хватало, чтобы колонка
                // `status` перестала быть хранилищем без записи.
                //
                // `null` — «не трогать», по той же причине, что и у `is_active`
                // в ensureComponent(): мутация `CreateComponent` статуса не передаёт
                // и не должна снимать вывод.
                //
                // `draft` не трогаем ни в какую сторону: конфиг выражает переход
                // active ↔ legacy, а «ещё не выпущено» — состояние разработки,
                // которое сеятель не вправе объявлять выпущенным.
                if (
                    $status !== null
                    && $variant->status !== $status
                    && $variant->status !== ComponentVariant::STATUS_DRAFT
                ) {
                    $variant->status = $status;
                    $variant->save();
                }

                return $variant;
            }

            $variant = ComponentVariant::create([
                'component_id' => $component->id,
                'version'      => $version,
                'name'         => $name,
                'article'      => $this->buildArticle($component, $version),
                'status'       => $status ?? ComponentVariant::STATUS_ACTIVE,
            ]);

            $variant->designSystems()->attach($this->ensureBaseDesignSystem()->id);

            return $variant;
        });
    }

    /**
     * Записать версии её конструкцию и роли, которые эта конструкция способна исполнить.
     *
     * Морфотип отвечает на вопрос «что блок ЕСТЬ», роли — «что он МОЖЕТ». Ни то ни
     * другое не говорит, чем блок стал у конкретного тенанта: выбранное назначение
     * и подпись живут в `page_components` и сюда не попадают.
     *
     * Роли синхронизируются ПОЛНОСТЬЮ (sync, не syncWithoutDetaching): источник
     * истины — config/component_morphotypes.php, и роль, убранная из конфига, обязана
     * исчезнуть из таблицы. Иначе однажды приписанная роль осталась бы навсегда,
     * а библиотека блоков продолжала бы предлагать конструкцию под назначение,
     * которому она больше не отвечает.
     *
     * Морфотип, в отличие от ролей, перезаписывается только непустым значением:
     * `null` означает «не выписан», и затирать им уже выписанную конструкцию
     * нельзя — иначе выпадение записи из конфига молча обнуляло бы данные.
     *
     * @param  list<string>  $roleSlugs
     */
    public function applyMorphotype(ComponentVariant $variant, ?string $morph, array $roleSlugs): ComponentVariant
    {
        return DB::transaction(function () use ($variant, $morph, $roleSlugs): ComponentVariant {
            if ($morph !== null && $variant->morph !== $morph) {
                $variant->morph = $morph;
                $variant->save();
            }

            $wanted = array_values(array_unique($roleSlugs));
            $current = $variant->roles()->pluck('role_slug')->all();

            $obsolete = array_diff($current, $wanted);
            if ($obsolete !== []) {
                $variant->roles()->whereIn('role_slug', $obsolete)->delete();
            }

            $missing = array_diff($wanted, $current);
            if ($missing !== []) {
                // Массовая вставка мимо модели: у таблицы составной первичный ключ
                // и нет собственного id, поэтому Eloquent-связь пригодна для чтения,
                // но не для записи по одной строке.
                DB::table('component_variant_role')->insert(array_map(
                    static fn (string $slug): array => [
                        'component_variant_id' => $variant->id,
                        'role_slug'            => $slug,
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ],
                    array_values($missing),
                ));
            }

            return $variant->load('roles');
        });
    }

    /**
     * Приписать версию к настоящей дизайн-системе, убрав её из Базовой.
     *
     * Ровно та операция, ради которой Базовая и существует: отрефакторенный компонент
     * уходит из песочницы в продукт. Версия может принадлежать нескольким настоящим
     * системам сразу, но членство в Базовой исключающее — поэтому её строка снимается.
     *
     * Идемпотентно: повторный вызов с той же системой не создаёт дубля.
     */
    public function assignToDesignSystem(ComponentVariant $variant, DesignSystem $system): ComponentVariant
    {
        if ($system->is_base) {
            throw new InvalidArgumentException(
                'Нельзя присвоить Базовую систему этим методом: она вход в жизненный цикл, '
                . 'а не назначение. Используйте ensureVariant() для новых версий.'
            );
        }

        return DB::transaction(function () use ($variant, $system): ComponentVariant {
            $variant->designSystems()->syncWithoutDetaching([$system->id]);

            $base = DesignSystem::query()->base()->first();

            if ($base) {
                $variant->designSystems()->detach($base->id);
            }

            return $variant->load('designSystems');
        });
    }

    /**
     * Вернуть Базовую систему, создав её при отсутствии.
     *
     * Обычно её создаёт data-миграция 2026_08_08_000006_seed_base_design_system,
     * но регистратор не должен падать на свежей схеме (например в тестах, где
     * прогоняются только нужные таблицы).
     */
    public function ensureBaseDesignSystem(): DesignSystem
    {
        $base = DesignSystem::query()->base()->first()
            ?? DesignSystem::where('slug', DesignSystem::BASE_SLUG)->first();

        if ($base) {
            return $base;
        }

        return DesignSystem::create([
            'name'        => 'Базовая',
            'slug'        => DesignSystem::BASE_SLUG,
            'description' => 'Песочница: легаси и версии в разработке, ещё не приписанные '
                . 'к настоящей дизайн-системе.',
            'is_base'     => true,
        ]);
    }

    /**
     * Вернуть в Базовую версию, не принадлежащую ни одной системе.
     */
    private function attachToBaseIfOrphaned(ComponentVariant $variant): void
    {
        if ($variant->designSystems()->exists()) {
            return;
        }

        $variant->designSystems()->attach($this->ensureBaseDesignSystem()->id);
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
