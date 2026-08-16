<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Роль, которую способна исполнить версия компонента: строка таблицы
 * component_variant_role.
 *
 * Это ВОЗМОЖНОСТЬ конструкции, а не выбор тенанта. Сетка карточек с иконкой
 * одинаково годится под «Преимущества», «Услуги» и «Этапы» — здесь три строки.
 * Что выбрал тенант, лежит в `page_components.role_slug`.
 *
 * `role_slug` не имеет внешнего ключа: справочник ролей живёт в
 * config/component_roles.php, а не в таблице — обоснование в шапке миграции
 * create_component_variant_role_table.
 *
 * Модель заведена для ЧТЕНИЯ (`$variant->roles`). Записывать построчно через неё
 * нельзя: у таблицы составной первичный ключ и нет собственного id, поэтому
 * Eloquent не умеет её обновлять и удалять по ключу. Синхронизацию делает
 * ComponentRegistrar::applyMorphotype() прямыми запросами.
 */
#[Fillable(['component_variant_id', 'role_slug'])]
class ComponentVariantRole extends Model
{
    protected $table = 'component_variant_role';

    /**
     * Собственного первичного ключа у таблицы нет — он составной
     * (component_variant_id + role_slug).
     */
    public $incrementing = false;

    /**
     * Версия компонента, которой принадлежит роль.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ComponentVariant::class, 'component_variant_id');
    }
}
