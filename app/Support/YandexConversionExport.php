<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Conversion;
use Carbon\CarbonImmutable;
use Generator;

final class YandexConversionExport
{
    public const PERIODS = ['day', 'week', 'month', 'quarter', 'year'];

    /**
     * Типы офлайн-конверсий, попадающие в выгрузку.
     *
     * `offline_promo_deal` — закрытая сделка по промокоду. Реестр конверсий
     * и есть точка передачи офлайн-конверсий в Яндекс: строку создаёт слушатель
     * доменного события, а наружу её выносит эта же выгрузка через человека.
     * Автоматической отправки нет — нет ни credentials, ни согласованного API.
     */
    public const EXPORTABLE_TYPES = ['offline_call', 'offline_email', Conversion::TYPE_PROMO_DEAL];

    private const MAX_AGE_DAYS = 113;

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function periodRange(string $period, string $date): array
    {
        $timezone = (string) config('app.timezone', 'Europe/Moscow');
        $anchor = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);

        [$start, $end] = match ($period) {
            'day' => [$anchor->startOfDay(), $anchor->endOfDay()],
            'week' => [$anchor->startOfWeek(), $anchor->endOfWeek()],
            'month' => [$anchor->startOfMonth(), $anchor->endOfMonth()],
            'quarter' => [$anchor->startOfQuarter(), $anchor->endOfQuarter()],
            'year' => [$anchor->startOfYear(), $anchor->endOfYear()],
        };

        $now = CarbonImmutable::now($timezone);
        $oldestAccepted = $now->subDays(self::MAX_AGE_DAYS)->startOfDay();

        return [
            $start->lessThan($oldestAccepted) ? $oldestAccepted : $start,
            $end->greaterThan($now) ? $now : $end,
        ];
    }

    /**
     * @param  iterable<Conversion>  $conversions
     * @return Generator<int, string>
     */
    public function lines(iterable $conversions): Generator
    {
        yield "id;create_date_time;emails;phones;order_status\r\n";

        foreach ($conversions as $conversion) {
            $row = $this->row($conversion);
            if ($row === null) {
                continue;
            }

            yield implode(';', array_map($this->escape(...), $row))."\r\n";
        }
    }

    /**
     * @return array<int, string>|null
     */
    public function row(Conversion $conversion): ?array
    {
        if ($conversion->channel !== Conversion::CHANNEL_OFFLINE
            || ! in_array($conversion->type, self::EXPORTABLE_TYPES, true)) {
            return null;
        }

        // Для ручного ввода канал известен из типа. Закрытая сделка по промокоду
        // приносит контакт клиента, который может быть и телефоном, и почтой, —
        // здесь тип не подсказывает, поэтому пробуем оба разбора.
        if ($conversion->type === Conversion::TYPE_PROMO_DEAL) {
            $email = $this->normalizeEmail($conversion->contact);
            $phone = $email === null ? $this->normalizePhone($conversion->contact) : null;
        } else {
            $email = $conversion->type === 'offline_email' ? $this->normalizeEmail($conversion->contact) : null;
            $phone = $conversion->type === 'offline_call' ? $this->normalizePhone($conversion->contact) : null;
        }

        if ($email === null && $phone === null) {
            return null;
        }

        $createdAt = $conversion->created_at?->copy()->timezone((string) config('app.timezone', 'Europe/Moscow'));
        if ($createdAt === null) {
            return null;
        }

        return [
            'lgt-offline-'.$conversion->getKey(),
            $createdAt->format('Y-m-d H:i:s'),
            $email ?? '',
            $phone ?? '',
            // Сделка по промокоду доходит сюда только закрытой — то есть
            // подтверждённой стороной и принятой платформой. Ручной ввод
            // такого подтверждения не несёт и остаётся «в работе».
            $conversion->type === Conversion::TYPE_PROMO_DEAL ? 'PAID' : 'IN_PROGRESS',
        ];
    }

    public function normalizeEmail(mixed $value): ?string
    {
        $email = strtolower(trim((string) $value));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return preg_match('/^[a-z0-9]/', $email) === 1 ? $email : null;
    }

    public function normalizePhone(mixed $value): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
            $phone = '7'.$phone;
        } elseif (strlen($phone) === 11 && str_starts_with($phone, '8')) {
            $phone = '7'.substr($phone, 1);
        }

        return preg_match('/^[1-9]\d{6,14}$/', $phone) === 1 ? $phone : null;
    }

    private function escape(string $value): string
    {
        if (strpbrk($value, ";\r\n\"") === false) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
