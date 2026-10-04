<?php

declare(strict_types=1);

namespace App\Services\Growth;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrderPortal
{
    public function plan(string $site, string $deal): object
    {
        abort_unless(DB::table('crm_deals')->where('license_id', $site)->where('id', $deal)->exists(), 404);
        DB::table('crm_order_plans')->insertOrIgnore(['id' => $deal, 'license_id' => $site, 'document_ids' => '[]', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['Замер', 'Проект', 'Производство', 'Монтаж'] as $position => $name) {
            DB::table('crm_order_milestones')->insertOrIgnore(['id' => (string) Str::ulid(), 'license_id' => $site, 'deal_id' => $deal, 'name' => $name, 'position' => $position, 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()]);
        }

        return DB::table('crm_order_plans')->where('license_id', $site)->where('id', $deal)->first();
    }

    public function invite(string $site, string $deal, int $actor): string
    {
        $this->plan($site, $deal);
        DB::table('crm_order_access')->where('license_id', $site)->where('deal_id', $deal)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::ulid();
        DB::table('crm_order_access')->insert(['id' => $id, 'license_id' => $site, 'deal_id' => $deal, 'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token), 'expires_at' => now()->addDays(7), 'created_by' => $actor, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function invitation(string $token, string $domain, bool $lock = false): object
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $q = DB::table('crm_order_access')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', now());
        $access = ($lock ? $q->lockForUpdate() : $q)->first();
        abort_unless($access && $this->domainMatches($access->license_id, $domain), 404, 'Приглашение недействительно или истекло. Запросите новую ссылку у менеджера.');

        return $access;
    }

    public function exchange(string $token, string $domain, string $key): array
    {
        return DB::transaction(function () use ($token, $domain, $key) {
            $access = $this->invitation($token, $domain, true);
            if ($access->used_at) {
                abort_unless($access->exchange_key === $key && $access->session_expires_at > now(), 409, 'Приглашение уже использовано. Запросите новую ссылку.');

                return ['session' => Crypt::decryptString($access->session_encrypted), 'expires_in' => max(0, now()->diffInSeconds($access->session_expires_at, false))];
            }
            $session = bin2hex(random_bytes(32));
            DB::table('crm_order_access')->where('id', $access->id)->update(['exchange_key' => $key, 'session_hash' => hash('sha256', $session), 'session_encrypted' => Crypt::encryptString($session), 'used_at' => now(), 'session_expires_at' => now()->addDays(30), 'updated_at' => now()]);

            return ['session' => $session, 'expires_in' => 30 * 86400];
        });
    }

    public function session(?string $token, string $domain): object
    {
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token), 401, 'Откройте приглашение от менеджера.');
        $access = DB::table('crm_order_access')->where('session_hash', hash('sha256', $token))->whereNull('revoked_at')->where('session_expires_at', '>', now())->first();
        abort_unless($access && $this->domainMatches($access->license_id, $domain), 401, 'Доступ завершён. Запросите новое приглашение.');

        return $access;
    }

    public function customerData(object $access): array
    {
        $deal = DB::table('crm_deals')->where('license_id', $access->license_id)->where('id', $access->deal_id)->first();
        abort_unless($deal, 404);
        $plan = DB::table('crm_order_plans')->where('license_id', $access->license_id)->where('id', $deal->id)->first();
        $client = DB::table('crm_clients')->where('license_id', $access->license_id)->where('id', $deal->client_id)->first(['name', 'email', 'phone']);
        $stage = DB::table('crm_stages')->where('license_id', $access->license_id)->where('id', $deal->stage_id)->value('name');
        $documents = DB::table('crm_documents')->where('license_id', $access->license_id)->where('deal_id', $deal->id)->whereIn('id', json_decode($plan?->document_ids ?? '[]', true))->orderByDesc('created_at')->get(['id', 'name', 'kind', 'mime', 'size', 'created_at']);

        return ['order' => ['id' => $deal->id, 'title' => $deal->title, 'stage' => $stage, 'amount' => $deal->amount, 'due_at' => $deal->due_at, 'contract_number' => $deal->contract_number, 'signed_at' => $deal->signed_at, 'specification' => json_decode($deal->specification ?: '[]', true)], 'client' => $client, 'milestones' => DB::table('crm_order_milestones')->where('license_id', $access->license_id)->where('deal_id', $deal->id)->orderBy('position')->get(['name', 'status', 'due_at', 'note', 'completed_at']), 'documents' => $documents];
    }

    private function domainMatches(string $site, string $domain): bool
    {
        $expected = DB::table('licenses')->where('id', $site)->value('domain');

        return is_string($expected) && rtrim(strtolower($expected), '.') === rtrim(strtolower($domain), '.');
    }
}
