<?php

declare(strict_types=1);

namespace App\Services\Growth;

use App\Models\License;
use App\Services\PublicSitePages;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SelectionsService
{
    public function __construct(private PublicSitePages $pages) {}

    public function site(string $domain): License
    {
        $domain = strtolower(preg_replace('/^www\./i', '', trim($domain)));
        $site = License::where('domain', $domain)->where('is_active', true)->first();
        abort_unless($site && ! in_array($site->status, ['suspended', 'cancelled'], true), 404, 'Сайт не найден.');

        return $site;
    }

    /** Resolve only public destinations; never trust names, images or URLs from a visitor. */
    public function catalog(License $site): array
    {
        $items = [];
        foreach ($this->pages->sources($site) as $path => $source) {
            if ($project = $source['project'] ?? null) {
                $items['project:'.$project->id] = ['kind' => 'project', 'id' => $project->id, 'title' => $project->value, 'path' => $path];
            }
        }
        foreach ($this->pages->destinations($site) as $ref => $destination) {
            if (str_starts_with($destination['path'], '/mebel')) {
                continue;
            }
            $items['material:'.$ref] = ['kind' => 'material', 'id' => $ref, 'title' => $destination['name'], 'path' => $destination['path']];
        }

        return $items;
    }

    public function create(License $site, array $data, string $editToken): array
    {
        $refs = array_values(array_unique(array_map(fn ($item) => $item['kind'].':'.$item['id'], $data['items'])));
        $hash = hash('sha256', json_encode([$data['title'], $refs], JSON_THROW_ON_ERROR));
        $existing = DB::table('project_selections')->where('license_id', $site->id)->where('creation_key', $data['creation_key'])->first();
        if ($existing) {
            return $this->replay($existing, $hash, $editToken);
        }
        $catalog = $this->catalog($site);
        foreach ($refs as $ref) {
            if (! isset($catalog[$ref])) {
                throw ValidationException::withMessages(['items' => 'Проект или материал больше не опубликован на этом сайте.']);
            }
        }
        $row = [
            'id' => (string) Str::ulid(), 'license_id' => $site->id,
            'token' => bin2hex(random_bytes(24)), 'edit_token_hash' => hash('sha256', $editToken),
            'creation_key' => $data['creation_key'], 'payload_hash' => $hash,
            'title' => $data['title'], 'item_refs' => json_encode($refs, JSON_THROW_ON_ERROR),
            'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
        ];
        try {
            DB::table('project_selections')->insert($row);
        } catch (UniqueConstraintViolationException $e) {
            $existing = DB::table('project_selections')->where('license_id', $site->id)->where('creation_key', $data['creation_key'])->first();
            if (! $existing) {
                throw $e;
            }

            return $this->replay($existing, $hash, $editToken);
        }

        return ['token' => $row['token'], 'expires_at' => $row['expires_at']->toIso8601String()];
    }

    public function active(License $site, string $token): object
    {
        $row = DB::table('project_selections')->where('license_id', $site->id)->where('token', $token)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($row, 404, 'Подборка недоступна. Ссылка истекла или была закрыта.');

        return $row;
    }

    public function show(License $site, string $token, ?string $editToken): array
    {
        $row = $this->active($site, $token);
        $catalog = $this->catalog($site);
        $items = [];
        foreach (json_decode($row->item_refs, true, flags: JSON_THROW_ON_ERROR) as $ref) {
            if (isset($catalog[$ref])) {
                $items[] = $catalog[$ref];
            }
        }
        $comments = DB::table('project_selection_comments')->where('selection_id', $row->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get(['id', 'author', 'body', 'created_at'])->reverse()->values();

        return ['title' => $row->title, 'token' => $row->token, 'expires_at' => $row->expires_at,
            'items' => $items, 'comments' => $comments, 'can_manage' => $editToken && hash_equals($row->edit_token_hash, hash('sha256', $editToken))];
    }

    public function comment(License $site, string $token, array $data): string
    {
        return DB::transaction(function () use ($site, $token, $data) {
            $row = $this->active($site, $token);
            $locked = DB::table('project_selections')->where('id', $row->id)->lockForUpdate()->first();
            abort_unless($locked && ! $locked->revoked_at && $locked->expires_at > now()->toDateTimeString(), 404);
            $existing = DB::table('project_selection_comments')->where('selection_id', $row->id)->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                if ($existing->author !== $data['author'] || $existing->body !== $data['body']) {
                    throw ValidationException::withMessages(['submission_key' => 'Ключ уже использован для другого комментария.']);
                }

                return $existing->id;
            }
            abort_if(DB::table('project_selection_comments')->where('selection_id', $row->id)->count() >= 100, 422, 'В подборке уже 100 комментариев.');
            $id = (string) Str::ulid();
            DB::table('project_selection_comments')->insert(['id' => $id, 'selection_id' => $row->id] + $data + ['created_at' => now(), 'updated_at' => now()]);

            return $id;
        });
    }

    public function revoke(License $site, string $token, string $editToken): void
    {
        $row = DB::table('project_selections')->where('license_id', $site->id)->where('token', $token)->first();
        abort_unless($row, 404);
        abort_unless(hash_equals($row->edit_token_hash, hash('sha256', $editToken)), 403, 'Закрыть ссылку может создатель подборки.');
        DB::table('project_selections')->where('id', $row->id)->update(['revoked_at' => $row->revoked_at ?: now(), 'updated_at' => now()]);
    }

    private function replay(object $row, string $hash, string $editToken): array
    {
        abort_unless(hash_equals($row->edit_token_hash, hash('sha256', $editToken)), 403);
        if (! hash_equals($row->payload_hash, $hash)) {
            throw ValidationException::withMessages(['creation_key' => 'Ключ уже использован для другой подборки.']);
        }
        abort_if($row->revoked_at || $row->expires_at <= now()->toDateTimeString(), 410, 'Создайте новую подборку.');

        return ['token' => $row->token, 'expires_at' => $row->expires_at];
    }
}
