<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * На что даётся скидка.
 *
 * Allowlist, а не свободный морф: скидку дают на категорию каталога, проект
 * мебели, услугу платформы или разовое предложение партнёра. Первые два —
 * существующие сущности с ULID, последние два ссылки не имеют вовсе, и общий
 * FK был бы возможен только для половины списка.
 *
 * `subject_title` заполняется всегда: именно его видят клиент и партнёр,
 * и он переживает переименование или удаление категории.
 */
enum PromoSubjectType: string
{
    case Category = 'category';

    case MebelProject = 'mebel_project';

    case Service = 'service';

    case Custom = 'custom';

    /** Таблица, в которой лежит `subject_id`, если тип на неё ссылается. */
    public function table(): ?string
    {
        return match ($this) {
            self::Category => 'categories',
            self::MebelProject => 'mebel_projects',
            self::Service, self::Custom => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Category => 'Категория каталога',
            self::MebelProject => 'Проект мебели',
            self::Service => 'Услуга',
            self::Custom => 'Разовое предложение',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
