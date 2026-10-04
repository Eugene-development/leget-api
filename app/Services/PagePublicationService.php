<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Publication snapshots contain tenant overrides only, never template defaults. */
final class PagePublicationService
{
    public function __construct(private TemplateService $templates) {}

    public function ownedLicense(User $user, string $id, bool $lock = false): License
    {
        $query = License::whereKey($id)->where('user_id', $user->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function state(User $user, string $licenseId, string $slug): array
    {
        $license = $this->ownedLicense($user, $licenseId);
        $this->validateSlug($license, $slug);
        $draft = DB::table('page_drafts')->where('license_id', $licenseId)->where('slug', $slug)->first();

        return [
            'draft' => $draft ? $this->metadata($draft) : null,
            'liveHash' => $this->hash($this->snapshot($license, $slug)),
            'revisions' => DB::table('page_revisions')->where('license_id', $licenseId)->where('slug', $slug)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(50)
                ->get(['id', 'action', 'content_hash', 'created_at'])->map(fn ($row) => (array) $row)->all(),
        ];
    }

    public function begin(User $user, string $licenseId, string $slug): array
    {
        return DB::transaction(function () use ($user, $licenseId, $slug) {
            $license = $this->ownedLicense($user, $licenseId, true);
            $this->validateSlug($license, $slug);
            $draft = DB::table('page_drafts')->where('license_id', $licenseId)->where('slug', $slug)->lockForUpdate()->first();
            if (! $draft) {
                $snapshot = $this->snapshot($license, $slug, true);
                $id = (string) Str::ulid();
                DB::table('page_drafts')->insert([
                    'id' => $id, 'license_id' => $licenseId, 'slug' => $slug, 'author_id' => $user->id,
                    'version' => 1, 'base_hash' => $this->hash($snapshot), 'snapshot' => $this->encode($snapshot),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $draft = DB::table('page_drafts')->where('id', $id)->first();
            }

            return $this->issuePreview($draft);
        });
    }

    public function edit(User $user, string $licenseId, string $id, int $version, array $operation): array
    {
        return DB::transaction(function () use ($user, $licenseId, $id, $version, $operation) {
            $license = $this->ownedLicense($user, $licenseId, true);
            $draft = $this->draft($licenseId, $id, $version);
            $snapshot = json_decode($draft->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $kind = $operation['kind'];
            $result = [];
            if ($kind === 'component') {
                $scope = ($operation['scope'] ?? 'page') === 'global' ? 'globalComponents' : 'components';
                $slug = $scope === 'globalComponents' ? '__global__' : $draft->slug;
                $this->validateType($license, $slug, $operation['type']);
                $index = array_search($operation['type'], array_column($snapshot[$scope], 'type'), true);
                $row = $index === false ? [
                    'id' => (string) Str::ulid(), 'type' => $operation['type'], 'is_active' => true,
                    'sort_order' => array_search($operation['type'], $this->allowedTypes($license, $slug), true),
                ] : $snapshot[$scope][$index];
                $row['data'] = $operation['data'];
                unset($row['data']['_componentId']);
                if ($index === false) {
                    $snapshot[$scope][] = $row;
                } else {
                    $snapshot[$scope][$index] = $row;
                }
                $result['componentId'] = $row['id'];
            } elseif ($kind === 'reset') {
                $found = false;
                foreach (['components', 'globalComponents'] as $scope) {
                    $snapshot[$scope] = array_values(array_filter($snapshot[$scope], function ($row) use ($operation, &$found) {
                        if ($row['id'] === $operation['componentId']) {
                            $found = true;

                            return false;
                        }

                        return true;
                    }));
                }
                if (! $found) {
                    abort(404, 'Блок не найден в черновике.');
                }
            } elseif ($kind === 'layout') {
                $snapshot['layout'][$operation['type'] === 'Header' ? 'header_data' : 'footer_data'] = $operation['data'];
            } elseif ($kind === 'seo') {
                foreach (['title', 'description', 'keywords'] as $field) {
                    $snapshot['page']['seo_'.$field] = trim($operation[$field] ?? '') ?: null;
                }
            } elseif ($kind === 'move') {
                $types = $this->allowedTypes($license, $draft->slug);
                $order = array_values(array_unique(array_merge(array_values(array_intersect($snapshot['page']['component_order'] ?? [], $types)), $types)));
                $index = array_search($operation['type'], $order, true);
                if ($index === false) {
                    abort(422, 'Блок не найден на странице.');
                }
                $target = $index + ($operation['direction'] === 'up' ? -1 : 1);
                if ($target >= 0 && $target < count($order)) {
                    [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
                }
                $snapshot['page']['component_order'] = $order;
                $result['order'] = $order;
            }
            $encoded = $this->encode($snapshot);
            if (strlen($encoded) > 2_000_000) {
                abort(422, 'Черновик слишком большой.');
            }
            DB::table('page_drafts')->where('id', $id)->update([
                'snapshot' => $encoded, 'version' => $version + 1, 'updated_at' => now(),
            ]);

            return array_merge($this->metadata(DB::table('page_drafts')->where('id', $id)->first()), $result);
        });
    }

    public function preview(User $user, string $licenseId, string $id, int $version): array
    {
        return DB::transaction(function () use ($user, $licenseId, $id, $version) {
            $this->ownedLicense($user, $licenseId, true);

            return $this->issuePreview($this->draft($licenseId, $id, $version));
        });
    }

    public function discard(User $user, string $licenseId, string $id, int $version): void
    {
        DB::transaction(function () use ($user, $licenseId, $id, $version) {
            $this->ownedLicense($user, $licenseId, true);
            $this->draft($licenseId, $id, $version);
            DB::table('page_drafts')->where('id', $id)->delete();
        });
    }

    public function publish(User $user, string $licenseId, string $id, int $version): array
    {
        $result = DB::transaction(function () use ($user, $licenseId, $id, $version) {
            $license = $this->ownedLicense($user, $licenseId, true);
            $draft = $this->draft($licenseId, $id, $version);
            $live = $this->snapshot($license, $draft->slug, true);
            if (! hash_equals($draft->base_hash, $this->hash($live))) {
                $this->conflict();
            }
            $snapshot = json_decode($draft->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $this->record($license, $user, $draft->slug, $live, 'baseline');
            $this->apply($license, $draft->slug, $snapshot);
            $revisionId = $this->record($license, $user, $draft->slug, $snapshot, 'publish');
            DB::table('page_drafts')->where('id', $id)->delete();

            return ['revisionId' => $revisionId, 'liveHash' => $this->hash($snapshot)];
        });
        $this->invalidate($licenseId);

        return $result;
    }

    public function restore(User $user, string $licenseId, string $revisionId, string $expectedHash): array
    {
        $result = DB::transaction(function () use ($user, $licenseId, $revisionId, $expectedHash) {
            $license = $this->ownedLicense($user, $licenseId, true);
            $revision = DB::table('page_revisions')->where('license_id', $licenseId)->where('id', $revisionId)->first();
            abort_unless($revision, 404);
            $live = $this->snapshot($license, $revision->slug, true);
            if (! hash_equals($expectedHash, $this->hash($live))) {
                $this->conflict();
            }
            // A restore must never silently throw away another editor's draft.
            if (DB::table('page_drafts')->where('license_id', $licenseId)->where('slug', $revision->slug)->exists()) {
                $this->conflict();
            }
            $snapshot = json_decode($revision->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $this->validateSlug($license, $revision->slug);
            $this->record($license, $user, $revision->slug, $live, 'baseline');
            $this->apply($license, $revision->slug, $snapshot);

            return [
                'revisionId' => $this->record($license, $user, $revision->slug, $snapshot, 'restore'),
                'liveHash' => $this->hash($snapshot),
            ];
        });
        $this->invalidate($licenseId);

        return $result;
    }

    /** Only the high-entropy capability can select a draft on a public request. */
    public function forRender(Request $request, License $license): ?array
    {
        $token = $request->header('X-Leget-Draft');
        if (! $token) {
            return null;
        }
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            abort(404);
        }
        $draft = DB::table('page_drafts')->where('license_id', $license->id)
            ->where('preview_hash', hash('sha256', $token))->where('preview_expires_at', '>', now())->first();
        abort_unless($draft, 404, 'Предпросмотр завершён.');

        return json_decode($draft->snapshot, true, flags: JSON_THROW_ON_ERROR);
    }

    /** Remove credential-like values even if old tenant content contains them. */
    public function publicPreview(array $response): array
    {
        $clean = function ($value) use (&$clean) {
            if (! is_array($value)) {
                return $value;
            }
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/password|secret|token|credential|authorization|private|api.?key/i', $key)) {
                    continue;
                }
                $result[$key] = $clean($item);
            }

            return $result;
        };
        foreach (['header', 'footer'] as $key) {
            if ($response['site'][$key] !== null) {
                $response['site'][$key]['data'] = $clean($response['site'][$key]['data']);
            }
        }
        foreach ($response['page']['componentsData'] as &$component) {
            $component['data'] = $clean($component['data']);
        }

        return $response;
    }

    public function snapshot(License $license, string $slug, bool $lock = false): array
    {
        $pages = Page::where('license_id', $license->id);
        if ($lock) {
            $pages->lockForUpdate();
        }
        $page = (clone $pages)->where('slug', $slug)->first();
        $global = (clone $pages)->where('slug', '__global__')->first();
        $rows = function (?Page $page) use ($license, $lock): array {
            if (! $page) {
                return [];
            }
            $query = PageComponent::where('license_id', $license->id)->where('page_id', $page->id)->orderBy('type');
            if ($lock) {
                $query->lockForUpdate();
            }

            return $query->get()->map(fn ($row) => [
                'id' => (string) $row->id, 'type' => $row->type, 'data' => $row->data,
                'is_active' => (bool) $row->is_active, 'sort_order' => (int) $row->sort_order,
            ])->all();
        };

        return [
            'page' => [
                'slug' => $slug, 'seo_title' => $page?->seo_title, 'seo_description' => $page?->seo_description,
                'seo_keywords' => $page?->seo_keywords, 'component_order' => $page?->component_order ?? [],
            ],
            'components' => $rows($page), 'globalComponents' => $rows($global),
            'layout' => ['header_data' => $license->header_data, 'footer_data' => $license->footer_data],
        ];
    }

    private function apply(License $license, string $slug, array $snapshot): void
    {
        $page = Page::firstOrCreate(['license_id' => $license->id, 'slug' => $slug]);
        foreach (['seo_title', 'seo_description', 'seo_keywords', 'component_order'] as $field) {
            $page->$field = $snapshot['page'][$field] ?? null;
        }
        $page->save();
        $this->replaceComponents($license, $page, $snapshot['components']);
        // Do not materialize an unused global page (or any lazy default rows).
        $global = Page::where('license_id', $license->id)->where('slug', '__global__')->first();
        if ($global || $snapshot['globalComponents']) {
            $global ??= Page::create(['license_id' => $license->id, 'slug' => '__global__']);
            $this->replaceComponents($license, $global, $snapshot['globalComponents']);
        }
        $license->header_data = $snapshot['layout']['header_data'];
        $license->footer_data = $snapshot['layout']['footer_data'];
        $license->save();
    }

    private function replaceComponents(License $license, Page $page, array $rows): void
    {
        PageComponent::where('license_id', $license->id)->where('page_id', $page->id)->delete();
        foreach ($rows as $row) {
            // Restoring historical overrides tolerates removed types; the renderer
            // still uses current template definitions as its structural gate.
            $component = new PageComponent($row);
            $component->id = $row['id'];
            $component->page_id = $page->id;
            $component->license_id = $license->id;
            $component->save();
        }
    }

    private function record(License $license, User $user, string $slug, array $snapshot, string $action): string
    {
        $hash = $this->hash($snapshot);
        // No duplicate baselines when current state was already captured.
        if ($action === 'baseline' && DB::table('page_revisions')->where('license_id', $license->id)->where('slug', $slug)->where('content_hash', $hash)->exists()) {
            return '';
        }
        $id = (string) Str::ulid();
        DB::table('page_revisions')->insert([
            'id' => $id, 'license_id' => $license->id, 'slug' => $slug, 'author_id' => $user->id,
            'action' => $action, 'snapshot' => $this->encode($snapshot), 'content_hash' => $hash, 'created_at' => now(),
        ]);

        return $id;
    }

    private function draft(string $licenseId, string $id, int $version): object
    {
        $draft = DB::table('page_drafts')->where('license_id', $licenseId)->where('id', $id)->lockForUpdate()->first();
        abort_unless($draft, 404);
        if ((int) $draft->version !== $version) {
            $this->conflict();
        }

        return $draft;
    }

    private function issuePreview(object $draft): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = now()->addHours(2);
        DB::table('page_drafts')->where('id', $draft->id)->update(['preview_hash' => hash('sha256', $token), 'preview_expires_at' => $expires]);

        return array_merge($this->metadata($draft), ['previewToken' => $token, 'previewExpiresAt' => $expires->toIso8601String()]);
    }

    private function metadata(object $draft): array
    {
        return ['id' => $draft->id, 'slug' => $draft->slug, 'version' => (int) $draft->version, 'updatedAt' => $draft->updated_at];
    }

    private function allowedTypes(License $license, string $slug): array
    {
        return $this->templates->getAllowedTypes((int) $license->template_id, $this->templates->resolveTemplateSlug((int) $license->template_id, $slug));
    }

    private function validateSlug(License $license, string $slug): void
    {
        if (! str_starts_with($slug, '/') || strlen($slug) > 255 || ! $this->allowedTypes($license, $slug)) {
            throw ValidationException::withMessages(['slug' => 'Страница отсутствует в шаблоне.']);
        }
    }

    private function validateType(License $license, string $slug, string $type): void
    {
        if (! in_array($type, $this->allowedTypes($license, $slug), true)) {
            throw ValidationException::withMessages(['type' => 'Блок отсутствует на этой странице.']);
        }
    }

    private function hash(array $snapshot): string
    {
        // DB and draft edits may order rows differently; order belongs to page.
        foreach (['components', 'globalComponents'] as $key) {
            usort($snapshot[$key], fn ($a, $b) => strcmp($a['type'], $b['type']));
        }
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', $this->encode($canonical($snapshot)));
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function conflict(): never
    {
        throw new HttpException(409, 'Страница или черновик уже изменились. Обновите страницу перед продолжением.');
    }

    private function invalidate(string $licenseId): void
    {
        try {
            Cache::tags(["license:{$licenseId}"])->flush();
        } catch (\BadMethodCallException) {
            Cache::flush();
        }
    }
}
