<?php

declare(strict_types=1);

namespace App\Services\Crm;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CrmStore
{
    public function query(string $table, string $site): Builder
    {
        return DB::table($table)->where('license_id', $site);
    }

    public function row(string $table, string $site, string $id, bool $lock = false): object
    {
        $q = $this->query($table, $site)->where('id', $id);
        $row = ($lock ? $q->lockForUpdate() : $q)->first();
        abort_unless($row, 404, 'Запись не найдена.');

        return $row;
    }

    /** Serialize mutations per site; the key and result commit with the business action. */
    public function mutate(Request $request, string $site, Closure $action, int $attempts = 3): array
    {
        $input = $request->validate(['request_key' => 'required|uuid']);
        $body = $request->except('request_key');
        // Uploaded bytes are included, not their temporary path.
        foreach ($request->allFiles() as $key => $file) {
            $body[$key] = [$file->getClientOriginalName(), hash_file('sha256', $file->getRealPath())];
        }
        ksort($body);
        $hash = hash('sha256', $request->method().' '.$request->path().' '.json_encode($body, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $site, $input, $hash, $action) {
            DB::table('licenses')->where('id', $site)->lockForUpdate()->first();
            // Recheck after the site lock: a concurrent revoke must win over stale authorization.
            app(CrmAccess::class)->site($request->user()->fresh(), $site);
            $existing = DB::table('crm_operations')->where('license_id', $site)->where('actor_id', $request->user()->id)
                ->where('request_key', $input['request_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Ключ операции уже использован с другими данными.');

                return json_decode($existing->result, true, flags: JSON_THROW_ON_ERROR);
            }
            $result = $action();
            DB::table('crm_operations')->insert([
                'id' => (string) Str::ulid(), 'license_id' => $site, 'actor_id' => $request->user()->id,
                'request_key' => $input['request_key'], 'payload_hash' => $hash,
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return $result;
        }, $attempts);
    }

    public function insert(string $table, string $site, array $data): object
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert($this->encode($data) + ['id' => $id, 'license_id' => $site, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $this->row($table, $site, $id);
    }

    public function update(string $table, object $row, int $version, array $data): object
    {
        abort_unless((int) $row->version === $version, 409, 'Запись изменил другой сотрудник. Обновите карточку.');
        $changed = DB::table($table)->where('id', $row->id)->where('license_id', $row->license_id)->where('version', $version)
            ->update($this->encode($data) + ['version' => $version + 1, 'updated_at' => now()]);
        abort_unless($changed, 409, 'Запись уже изменена.');

        return $this->row($table, $row->license_id, $row->id);
    }

    public function event(Request $request, string $site, string $type, string $id, string $action, array $data = [], ?string $client = null, ?string $deal = null): void
    {
        DB::table('crm_events')->insert([
            'id' => (string) Str::ulid(), 'license_id' => $site, 'entity_type' => $type, 'entity_id' => $id,
            'actor_id' => $request->user()->id, 'action' => $action, 'data' => json_encode(array_merge($data, ['actor_name' => $request->user()->name]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'client_id' => $client, 'deal_id' => $deal, 'created_at' => now(),
        ]);
    }

    public function present(object $row): array
    {
        $data = (array) $row;
        foreach (['requisites', 'content', 'specification', 'snapshot', 'data', 'details'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = json_decode($data[$field], true);
            }
        }
        foreach ($data as $key => $value) {
            if (str_ends_with($key, '_at') && is_string($value) && strlen($value) > 10) {
                $data[$key] = Carbon::parse($value, config('app.timezone'))->toIso8601String();
            }
        }
        unset($data['path'], $data['disk'], $data['payload_hash'], $data['submission_key'], $data['ip_address'], $data['user_agent'], $data['recipient_email']);

        return $data;
    }

    private function encode(array $data): array
    {
        return array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $v, $data);
    }
}
