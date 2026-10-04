<?php

declare(strict_types=1);

namespace App\Services\Growth;

use App\Models\User;
use App\Services\Crm\CrmAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AutomationService
{
    public const TRIGGERS = ['unassigned', 'stage_entered', 'stale_stage', 'signed', 'paid'];

    public function run(?string $site = null): int
    {
        $count = 0;
        $rules = DB::table('growth_automation_rules')->where('enabled', true)->when($site, fn ($q) => $q->where('license_id', $site))->get();
        foreach ($rules as $rule) {
            $table = $rule->trigger === 'unassigned' ? 'service_requests' : 'crm_deals';
            DB::table($table)->where('license_id', $rule->license_id)->orderBy('id')->chunkById(100, function ($rows) use ($rule, $table, &$count) {
                foreach ($rows as $row) {
                    $count += DB::transaction(function () use ($rule, $table, $row) {
                        // Same site lock as CRM mutations: claim, notification and task commit together.
                        $license = DB::table('licenses')->where('id', $rule->license_id)->lockForUpdate()->first();
                        $freshRule = DB::table('growth_automation_rules')->where('id', $rule->id)->where('enabled', true)->first();
                        $fresh = DB::table($table)->where('license_id', $rule->license_id)->where('id', $row->id)->first();
                        if (! $license || ! $freshRule || ! $fresh) {
                            return 0;
                        }
                        $occurrence = $this->occurrence($freshRule, $fresh);
                        if ($occurrence === null || DB::table('growth_automation_runs')->where('rule_id', $rule->id)->where('entity_id', $row->id)->where('occurrence', $occurrence)->exists()) {
                            return 0;
                        }
                        $recipient = $freshRule->assigned_to ?: ($fresh->assigned_to ?: $license->user_id);
                        try {
                            app(CrmAccess::class)->site(User::findOrFail($recipient), $rule->license_id);
                        } catch (\Throwable) {
                            $recipient = $license->user_id;
                        }
                        $task = null;
                        if ($freshRule->task_title) {
                            $task = (string) Str::ulid();
                            DB::table('crm_tasks')->insert([
                                'id' => $task, 'license_id' => $rule->license_id, 'title' => $freshRule->task_title,
                                'kind' => 'task', 'assigned_to' => $recipient,
                                'deal_id' => $table === 'crm_deals' ? $fresh->id : $fresh->crm_deal_id,
                                'client_id' => $table === 'crm_deals' ? $fresh->client_id : $fresh->crm_client_id,
                                'due_at' => now()->addMinutes($freshRule->due_minutes), 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                            ]);
                        }
                        DB::table('user_notifications')->insert([
                            'id' => (string) Str::ulid(), 'user_id' => $recipient, 'type' => 'crm.automation',
                            'title' => $freshRule->name, 'body' => $table === 'crm_deals' ? 'Проверьте сделку и следующий шаг в CRM.' : 'Заявка ожидает назначения ответственного в CRM.',
                            'payload' => json_encode(['license_id' => $rule->license_id, 'url' => '/crm/'.($table === 'crm_deals' ? 'deals' : 'incoming').'/'.$fresh->id.'?site='.$rule->license_id, 'task_id' => $task], JSON_THROW_ON_ERROR),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        DB::table('growth_automation_runs')->insert(['id' => (string) Str::ulid(), 'license_id' => $rule->license_id, 'rule_id' => $rule->id, 'entity_id' => $row->id, 'occurrence' => $occurrence, 'task_id' => $task, 'created_at' => now()]);
                        DB::table('crm_events')->insert(['id' => (string) Str::ulid(), 'license_id' => $rule->license_id, 'entity_type' => $table === 'crm_deals' ? 'deals' : 'incoming', 'entity_id' => $fresh->id,
                            'deal_id' => $table === 'crm_deals' ? $fresh->id : $fresh->crm_deal_id, 'client_id' => $table === 'crm_deals' ? $fresh->client_id : $fresh->crm_client_id,
                            'actor_id' => null, 'action' => 'automation', 'data' => json_encode(['rule_id' => $rule->id, 'task_id' => $task], JSON_THROW_ON_ERROR), 'created_at' => now()]);

                        return 1;
                    }, 3);
                }
            });
        }

        return $count;
    }

    private function occurrence(object $rule, object $row): ?string
    {
        if ($rule->trigger === 'unassigned') {
            if ($row->assigned_to || $row->status !== 'new' || $row->service_type === 'manager-access') {
                return null;
            }

            return $this->due($row->created_at, $rule->delay_minutes) ? hash('sha256', 'unassigned:'.$row->created_at) : null;
        }
        if ($row->closed_at || $row->paused) {
            return null;
        }
        $stage = DB::table('crm_stages')->where('license_id', $rule->license_id)->where('id', $row->stage_id)->first();
        if (! $stage || $stage->archived || in_array($stage->kind, ['won', 'lost'], true)) {
            return null;
        }
        if (in_array($rule->trigger, ['stale_stage', 'stage_entered'], true)) {
            if ($rule->stage_id !== $row->stage_id) {
                return null;
            }
            $event = DB::table('crm_events')->where('license_id', $rule->license_id)->where('entity_type', 'deals')->where('entity_id', $row->id)->where('action', 'stage')->orderByDesc('created_at')->orderByDesc('id')->first();
            $at = $event?->created_at ?: $row->created_at;
            if ($rule->trigger === 'stage_entered' && CarbonImmutable::parse($at)->lt(CarbonImmutable::parse($rule->created_at))) {
                return null;
            }

            return $this->due($at, $rule->delay_minutes) ? hash('sha256', $row->stage_id.':'.($event?->id ?: $row->created_at)) : null;
        }
        if (! $row->signed_at || ! $row->contract_number || ! $row->contract_date) {
            return null;
        }
        if ($rule->trigger === 'signed') {
            return $this->due($row->signed_at, $rule->delay_minutes) ? hash('sha256', 'signed:'.$row->signed_at) : null;
        }
        $net = '0.00';
        $last = null;
        foreach (DB::table('crm_payments')->where('license_id', $rule->license_id)->where('deal_id', $row->id)->orderBy('created_at')->get() as $p) {
            $net = $p->kind === 'refund' ? bcsub($net, (string) $p->amount, 2) : bcadd($net, (string) $p->amount, 2);
            $last = $p->created_at;
        }
        if (! $last || bccomp((string) $row->amount, '0', 2) <= 0 || bccomp($net, (string) $row->amount, 2) < 0) {
            return null;
        }

        return $this->due($last, $rule->delay_minutes) ? hash('sha256', 'paid:'.$row->id) : null;
    }

    private function due(string $at, int $minutes): bool
    {
        return CarbonImmutable::parse($at, config('app.timezone'))->addMinutes($minutes)->lte(now());
    }
}
