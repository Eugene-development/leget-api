<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdAttribution;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Перенос рекламной атрибуции из браузера в БД.
 *
 * Пишет только по токену самого клиента и только про него: `user_id` берётся
 * из аутентифицированного пользователя, а не из тела запроса. Подставить чужой
 * идентификатор нечем.
 *
 * Первое касание записывается ОДИН раз. Это не оптимизация, а граница:
 * вознаграждение куратора считается по сделкам, выросшим из рекламы, и если бы
 * источник можно было переписать позже, переписывал бы его тот, кому это
 * выгодно. Повторные вызовы обновляют только `last_*`.
 *
 * Ни один маршрут куратора, партнёра или админа сюда не ведёт — см. routes/web.php.
 */
final class AttributionRecorder
{
    /**
     * @param  array<string, mixed>  $payload  Проверенные данные из cookie leget-main:
     *                                         `visitor_id`, `first` и `last` — наборы
     *                                         полей одного касания.
     */
    public function record(User $client, array $payload): ?AdAttribution
    {
        $first = $this->touch($payload['first'] ?? null);
        $last = $this->touch($payload['last'] ?? null);

        // Пустая атрибуция — это «человек пришёл не из рекламы». Заводить для
        // него строку из одних NULL значило бы засорять отчёт источниками,
        // которых не было.
        if ($first === null && $last === null) {
            return null;
        }

        $attribution = AdAttribution::query()->where('user_id', $client->id)->first();

        if (! $attribution instanceof AdAttribution) {
            $attribution = new AdAttribution;
            $attribution->forceFill([
                'user_id' => $client->id,
                'visitor_id' => $this->text($payload['visitor_id'] ?? null, 64),
            ]);

            // Если известно только последнее касание, оно же и первое:
            // человек впервые пришёл именно сейчас.
            $attribution->forceFill($this->prefixed('first', $first ?? $last));
        }

        // Последнее касание обновляется всегда — но только при непустых данных.
        // Обычный вход без рекламных меток не должен затирать источник.
        if ($last !== null) {
            $attribution->forceFill($this->prefixed('last', $last));
        } elseif ($attribution->last_touched_at === null) {
            $attribution->forceFill($this->prefixed('last', $first));
        }

        $attribution->save();

        return $attribution;
    }

    /**
     * Нормализованное касание или null, если в нём нет ни одного значения.
     *
     * @return array<string, mixed>|null
     */
    private function touch(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $touch = [];
        $hasValue = false;

        foreach (AdAttribution::TOUCH_FIELDS as $field) {
            // URL'ы лежат в TEXT — их не режем до 255; идентификаторы и метки
            // ограничены длиной своих колонок.
            $limit = in_array($field, ['landing_url', 'referrer'], true) ? 2048 : 255;
            $value = $this->text($raw[$field] ?? null, $limit);

            $touch[$field] = $value;
            $hasValue = $hasValue || $value !== null;
        }

        $touch['touched_at'] = $this->timestamp($raw['touched_at'] ?? null);

        if (! $hasValue) {
            return null;
        }

        // Время касания — не признак касания: метки есть, отметки времени нет
        // (устаревшая cookie). Ставим момент переноса, чтобы интервалы
        // «переход → регистрация» считались, пусть и с погрешностью.
        $touch['touched_at'] ??= CarbonImmutable::now();

        return $touch;
    }

    /**
     * @param  array<string, mixed>|null  $touch
     * @return array<string, mixed>
     */
    private function prefixed(string $prefix, ?array $touch): array
    {
        if ($touch === null) {
            return [];
        }

        $prefixed = [];

        foreach ($touch as $field => $value) {
            $prefixed[$prefix.'_'.$field] = $value;
        }

        return $prefixed;
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $moment = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // Клиент прислал время из своего браузера. Будущее — либо сбитые часы,
        // либо попытка растянуть интервал «переход → регистрация»; ни то ни
        // другое в отчёте не нужно.
        return $moment->isFuture() ? CarbonImmutable::now() : $moment;
    }
}
