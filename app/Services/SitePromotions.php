<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SitePromotions
{
    private const BLOCKS = [
        1 => [
            '/' => ['PartnerOffers' => 'Предложения партнёров', 'PromoOffer' => 'Спецпредложение на главной'],
            '/actions' => ['ActionsCards' => 'Карточки акций', 'ActionsCardsExtra' => 'Акции компаний', 'ActionsBanner' => 'Баннер акций'],
        ],
        2 => [
            '/actions' => ['ActionsTimer' => 'Сезонная акция', 'ActionsCards' => 'Карточки акций', 'ActionsBanner' => 'Баннер акции'],
        ],
    ];

    public function __construct(private TemplateService $templates, private PagePublicationService $publication) {}

    public function supports(License $license, ?string $slug, ?string $type = null): bool
    {
        $blocks = self::BLOCKS[(int) $license->template_id][$slug] ?? [];

        return $type === null ? $blocks !== [] : isset($blocks[$type]);
    }

    public static function componentVersion(?PageComponent $component): string
    {
        return $component?->exists
            ? hash('sha256', json_encode([$component->id, $component->data, $component->is_active], JSON_THROW_ON_ERROR))
            : 'default';
    }

    /** Keep normal inline edits, resets and deletes in the same history as CRM changes. */
    public function change(License $license, ?string $slug, ?string $type, int $userId, Closure $change): mixed
    {
        if (! $this->supports($license, $slug, $type)) {
            return $change();
        }

        return DB::transaction(function () use ($license, $slug, $userId, $change) {
            $site = License::whereKey($license->id)->lockForUpdate()->firstOrFail();
            $before = $this->publication->snapshot($site, $slug, true);
            $result = $change();
            $after = $this->publication->snapshot($site, $slug, true);
            if ($this->entries($site, $slug, $before) !== $this->entries($site, $slug, $after)) {
                $this->publication->recordPromotionChange($site, $userId, $slug, $before, $after);
            }
            DB::afterCommit(fn () => $this->publication->invalidate($site->id));

            return $result;
        }, 3);
    }

    public function materialize(License $license): void
    {
        foreach (self::BLOCKS[(int) $license->template_id] ?? [] as $slug => $types) {
            $page = Page::firstOrCreate(['license_id' => $license->id, 'slug' => $slug]);
            foreach ($this->definitions($license, $slug) as $type => $definition) {
                if (! isset($types[$type])) {
                    continue;
                }
                $component = $page->pageComponents()->firstOrNew(['license_id' => $license->id, 'type' => $type]);
                $data = $this->blockData($license, $type, $component->data ?? $definition['defaults'], $definition['defaults']);
                if (! $component->exists && ! $this->placements($license, $type, $data)) {
                    continue;
                }
                if (! $component->exists) {
                    $component->is_active = true;
                    $component->sort_order = $definition['position'];
                }
                $component->data = $data;
                if ($component->isDirty()) {
                    $component->save();
                }
            }
        }
    }

    public function inventory(License $license): array
    {
        $result = [];
        foreach (self::BLOCKS[(int) $license->template_id] ?? [] as $slug => $types) {
            $live = $this->entries($license, $slug, $this->publication->snapshot($license, $slug));
            $archived = [];
            foreach ($this->revisions($license, $slug) as [$revision, $entries]) {
                foreach (array_diff_key($entries, $live, $archived) as $id => $entry) {
                    $archived[$id] = $this->present($entry, true);
                }
            }
            $result = array_merge($result, array_map(fn (array $entry) => $this->present($entry), $live), $archived);
        }

        return array_values($result);
    }

    public function history(License $license, string $id): array
    {
        $history = [];
        foreach (self::BLOCKS[(int) $license->template_id] ?? [] as $slug => $types) {
            $previous = null;
            foreach ($this->revisions($license, $slug) as [$revision, $entries]) {
                $entry = $entries[$id] ?? null;
                $version = $entry ? $this->fingerprint($entry) : 'removed';
                if ($version === $previous) {
                    continue;
                }
                $previous = $version;
                $history[] = [
                    'id' => $revision->id,
                    'date' => CarbonImmutable::parse($revision->created_at, config('app.timezone'))->toIso8601String(),
                    'author' => $revision->author,
                    'present' => $entry !== null,
                    'endsAt' => $entry ? $this->deadline($entry['data'], $entry['type'])?->toIso8601String() : null,
                    'enabled' => $entry ? $this->enabled($entry) : false,
                ];
            }
            // A promotion ID belongs to only one placement on one page.
            if (array_filter($history, fn (array $item) => $item['present'])) {
                return array_slice($history, 0, 50);
            }
            $history = [];
        }

        return [];
    }

    public function save(License $license, int $userId, array $input, ?string $id = null): array
    {
        $entry = $id ? $this->locate($license, $id) : null;
        $slug = $entry['slug'] ?? '/actions';
        $type = $entry['type'] ?? 'ActionsCards';
        abort_unless($this->supports($license, $slug, $type), 422, 'На этом шаблоне нет блока акций.');

        return $this->change($license, $slug, $type, $userId, function () use ($license, $input, $id, $entry, $slug, $type) {
            $current = $this->entries($license, $slug, $this->publication->snapshot($license, $slug, true));
            $live = $id ? ($current[$id] ?? null) : null;
            $entry = $live ?? $entry;
            abort_if($id && ! hash_equals($this->fingerprint($entry, ! $live), $input['version']), 409, 'Акция уже изменена. Обновите список.');
            $page = Page::firstOrCreate(['license_id' => $license->id, 'slug' => $slug]);
            $definition = $this->definitions($license, $slug)[$type];
            $component = $page->pageComponents()->firstOrNew(['license_id' => $license->id, 'type' => $type]);
            $data = $this->blockData($license, $type, $component->data ?? $definition['defaults'], $definition['defaults']);
            $card = $entry['data'] ?? ['id' => (string) Str::ulid(), 'title' => $input['title']];
            if (array_key_exists('ends_at', $input)) {
                $card['expiresAt'] = $input['ends_at'];
            }
            $deadline = $this->deadline($card, $type);
            if ($type === 'ActionsTimer') {
                // A season timer always has a target date.
                abort_unless($deadline, 422, 'Укажите окончание сезонной акции.');
            }
            abort_if($input['enabled'] && $deadline?->lessThanOrEqualTo(now()), 422, 'Для публикации укажите дату окончания в будущем.');
            $card['enabled'] = $input['enabled'];
            $path = $live['path'] ?? $entry['path'] ?? ['cards', count($data['cards'])];
            if (! $entry && (int) $license->template_id === 2) {
                $card['category'] = 'furniture';
            }
            if ($entry && ! $live && $path !== []) {
                if ($type === 'ActionsCardsExtra') {
                    $company = $entry['company'];
                    $index = array_search((string) $company['id'], array_map(fn ($item) => (string) $item['id'], $data['companies']), true);
                    if ($index === false) {
                        $index = count($data['companies']);
                        $data['companies'][] = [...$company, 'enabled' => true, 'cards' => []];
                    }
                    $path = ['companies', $index, 'cards', count($data['companies'][$index]['cards'])];
                } else {
                    $path = [$path[0], count($data[$path[0]])];
                }
            }
            if ($path === []) {
                $data = $card;
            } else {
                data_set($data, implode('.', $path), $card);
            }
            if ($input['enabled']) {
                $versionKey = lcfirst($type).'Version';
                if ($path === [] && ($data[$versionKey] ?? null) === 'disabled') {
                    $data[$versionKey] = 'v1';
                }
                // Publishing a card must not unhide its siblings.
                abort_if($path !== [] && (($component->exists && ! $component->is_active)
                    || ($data[$versionKey] ?? null) === 'disabled'
                    || ($type === 'ActionsCardsExtra' && ($data['companies'][$path[1]]['enabled'] ?? true) === false)),
                    422, 'Сначала включите блок акций или компанию в редакторе сайта.');
            }

            if (! $component->exists) {
                $component->sort_order = $definition['position'];
                $component->is_active = true;
            } elseif ($input['enabled']) {
                $component->is_active = true;
            }
            $component->data = $data;
            $component->save();

            return ['success' => true];
        });
    }

    private function locate(License $license, string $id): array
    {
        foreach (self::BLOCKS[(int) $license->template_id] ?? [] as $slug => $types) {
            $entries = $this->entries($license, $slug, $this->publication->snapshot($license, $slug, true));
            if (isset($entries[$id])) {
                return $entries[$id];
            }
            foreach ($this->revisions($license, $slug) as [$revision, $entries]) {
                if (isset($entries[$id])) {
                    return $entries[$id];
                }
            }
        }
        abort(404, 'Акция не найдена.');
    }

    private function definitions(License $license, string $slug): array
    {
        $result = [];
        foreach ($this->templates->getPageComponents((int) $license->template_id, $slug) as $position => $definition) {
            $result[$definition['type']] = $definition + ['position' => $position];
        }

        return $result;
    }

    private function entries(License $license, string $slug, array $snapshot): array
    {
        $rows = array_column($snapshot['components'], null, 'type');
        $result = [];
        foreach ($this->definitions($license, $slug) as $type => $definition) {
            if (! $this->supports($license, $slug, $type)) {
                continue;
            }
            $row = $rows[$type] ?? null;
            $data = $this->blockData($license, $type, $row['data'] ?? $definition['defaults'], $definition['defaults']);
            $available = ($row['is_active'] ?? true) && ($data[lcfirst($type).'Version'] ?? null) !== 'disabled';
            foreach ($this->placements($license, $type, $data) as [$path, $card, $company]) {
                $id = hash('sha256', json_encode([$license->id, $slug, $type, (string) ($company['id'] ?? ''), (string) ($path === [] ? '' : $card['id'])], JSON_THROW_ON_ERROR));
                $result[$id] = ['id' => $id, 'slug' => $slug, 'type' => $type, 'data' => $card, 'company' => $company,
                    'path' => $path, 'available' => $available, 'placement' => self::BLOCKS[(int) $license->template_id][$slug][$type]];
            }
        }

        return $result;
    }

    private function placements(License $license, string $type, array $data): array
    {
        return match ($type) {
            'ActionsCards' => array_map(fn ($index, $card) => [['cards', $index], $card, null], array_keys($data['cards']), $data['cards']),
            'PartnerOffers' => array_map(fn ($index, $card) => [['offers', $index], $card, null], array_keys($data['offers']), $data['offers']),
            'ActionsCardsExtra' => $this->companyPlacements($data['companies']),
            'ActionsBanner' => (int) $license->template_id === 1 && empty($data['imageUrl']) ? [] : [[[], $data, null]],
            default => [[[], $data, null]],
        };
    }

    private function companyPlacements(array $companies): array
    {
        $result = [];
        foreach ($companies as $i => $company) {
            $cards = $company['cards'];
            unset($company['cards']);
            foreach ($cards as $j => $card) {
                $result[] = [['companies', $i, 'cards', $j], $card, $company];
            }
        }

        return $result;
    }

    private function blockData(License $license, string $type, array $data, array $defaults): array
    {
        if ($type === 'PartnerOffers') {
            $data['offers'] = $this->withIds(($data['offers'] ?? []) ?: ($defaults['offers'] ?? []), 'offer');
        } elseif ($type === 'ActionsCards') {
            $cards = $this->withIds($data['cards'] ?? $defaults['cards'] ?? [], 'card', $defaults['cards'] ?? []);
            $data['cards'] = (int) $license->template_id === 1
                ? ($cards ? $this->withDisabledDefaults($cards, $defaults['cards'] ?? []) : ($defaults['cards'] ?? []))
                : $cards;
        } elseif ($type === 'ActionsCardsExtra') {
            $defaultCards = $defaults['companies'][0]['cards'] ?? [];
            if (! is_array($data['companies'] ?? null)) {
                $legacy = $this->withIds($data['cards'] ?? $defaultCards, 'card', $defaultCards);
                $data['companies'] = [[
                    'id' => 'legacy', 'name' => 'Акции компаний',
                    'cards' => $legacy ? $this->withDisabledDefaults($legacy, $defaultCards) : [],
                ]];
            }
            $data['companies'] = $this->withIds($data['companies'], 'company');
            foreach ($data['companies'] as &$company) {
                $company['cards'] = $this->withIds($company['cards'] ?? [], 'card', $defaults['companies'][0]['cards'] ?? []);
            }
            unset($data['cards']);
        }
        unset($data['_componentId'], $data['_promotionVersion']);

        return $data;
    }

    private function withDisabledDefaults(array $cards, array $defaults): array
    {
        $ids = array_map('strval', array_column($cards, 'id'));
        $titles = array_column($cards, 'title');
        foreach ($defaults as $default) {
            if (! in_array((string) $default['id'], $ids, true) && ! in_array($default['title'], $titles, true)) {
                $cards[] = $default + ['enabled' => false];
            }
        }

        return $cards;
    }

    private function withIds(mixed $items, string $prefix, array $defaults = []): array
    {
        $result = [];
        foreach (is_array($items) ? array_values($items) : [] as $i => $item) {
            if (! is_array($item)) {
                continue;
            }
            $match = array_search($item['title'] ?? null, array_column($defaults, 'title'), true);
            if (! isset($item['id']) || $item['id'] === '') {
                $item['id'] = $match !== false ? $defaults[$match]['id'] : "$prefix-$i";
            }
            $result[] = $item;
        }

        return $result;
    }

    private function revisions(License $license, string $slug): iterable
    {
        $rows = DB::table('page_revisions')->leftJoin('users', 'users.id', '=', 'page_revisions.author_id')
            ->where('page_revisions.license_id', $license->id)->where('page_revisions.slug', $slug)
            ->orderByDesc('page_revisions.created_at')->orderByDesc('page_revisions.id')
            ->select('page_revisions.*', 'users.name as author')->cursor();
        foreach ($rows as $row) {
            yield [$row, $this->entries($license, $slug, json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR))];
        }
    }

    private function present(array $entry, bool $archived = false): array
    {
        $data = $entry['data'];
        $ends = $this->deadline($data, $entry['type']);

        return [
            'id' => $entry['id'],
            'slug' => $entry['slug'],
            'placement' => strip_tags((string) ($entry['company']['name'] ?? $entry['placement'])),
            'title' => html_entity_decode(strip_tags((string) ($data['offer'] ?? $data['title'] ?? $data['heading'] ?? $data['imageAlt'] ?? $entry['placement'])), ENT_QUOTES | ENT_HTML5),
            'endsAt' => $ends?->toIso8601String(),
            'status' => match (true) {
                $archived => 'removed',
                $ends !== null && $ends->lessThanOrEqualTo(now()) => 'expired',
                ! $this->enabled($entry) => 'hidden',
                default => 'active',
            },
            'enabled' => $this->enabled($entry),
            'requiresDeadline' => $entry['type'] === 'ActionsTimer',
            'parentHidden' => ! $archived && $entry['path'] !== []
                && (! $entry['available'] || ($entry['company']['enabled'] ?? true) === false),
            'version' => $this->fingerprint($entry, $archived),
        ];
    }

    private function enabled(array $entry): bool
    {
        return $entry['available'] && ($entry['company']['enabled'] ?? true) !== false && ($entry['data']['enabled'] ?? true) !== false;
    }

    private function fingerprint(array $entry, bool $archived = false): string
    {
        return hash('sha256', json_encode([$entry, $archived], JSON_THROW_ON_ERROR));
    }

    public function deadline(array $data, string $type): ?CarbonImmutable
    {
        if (array_key_exists('expiresAt', $data)) {
            $time = $data['expiresAt'];
            if (! is_string($time) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $time)) {
                return null;
            }

            try {
                $parsed = CarbonImmutable::make($time);

                return $parsed?->format('Y-m-d\TH:i:s') === substr($time, 0, 19) ? $parsed : null;
            } catch (InvalidFormatException) {
                return null;
            }
        }

        if ($type === 'ActionsTimer') {
            $time = $data['deadline'] ?? '2026-12-31T00:00:00';
            if (! is_string($time)) {
                return null;
            }
            if (preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $time)) {
                return $this->deadline(['expiresAt' => $time], $type);
            }

            return $this->localTime(str_replace('T', ' ', $time), strlen($time) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s');
        }

        $time = $data['validUntil'] ?? $data['until'] ?? null;
        if (! is_string($time) || trim($time) === '') {
            return null;
        }
        $time = trim($time);
        if (preg_match('/^(\d{1,2})\s+([а-я]+)\s+(\d{4})(?:\s+г\.?)?$/iuD', $time, $parts)) {
            $month = array_search(mb_strtolower($parts[2]), ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'], true);
            $time = sprintf('%02d.%02d.%04d', $parts[1], $month === false ? 0 : $month + 1, $parts[3]);
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/D', $time, $parts)) {
            $time = sprintf('%02d.%02d.%04d', $parts[1], $parts[2], $parts[3]);
        }

        return $this->localTime($time, str_contains($time, '.') ? 'd.m.Y' : 'Y-m-d')?->addDay();
    }

    private function localTime(string $value, string $format): ?CarbonImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('Europe/Moscow'));

        return $parsed && $parsed->format($format) === $value ? CarbonImmutable::instance($parsed) : null;
    }
}
