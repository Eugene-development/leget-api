<?php

declare(strict_types=1);

namespace App\Http\Controllers\Growth;

use App\Http\Controllers\Controller;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmStore;
use App\Services\Growth\AutomationService;
use App\Services\Growth\InsightsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class InsightsController extends Controller
{
    public function __construct(private CrmAccess $access, private CrmStore $store) {}

    public function insights(Request $r, string $site, InsightsService $service)
    {
        $this->access->site($r->user(), $site);
        $r->merge(['from' => $r->query('from', now('Europe/Moscow')->startOfMonth()->toDateString()), 'to' => $r->query('to', now('Europe/Moscow')->toDateString())]);
        $v = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        abort_if(CarbonImmutable::parse($v['from'])->diffInDays(CarbonImmutable::parse($v['to'])) > 1095, 422, 'Выберите период до трёх лет.');

        return response()->json($service->report($site, $v['from'], $v['to']));
    }

    public function automation(Request $r, string $site)
    {
        $license = $this->access->site($r->user(), $site);

        return response()->json(['manage' => $this->access->manages($r->user(), $license),
            'rules' => DB::table('growth_automation_rules')->where('license_id', $site)->orderBy('created_at')->get(),
            'runs' => DB::table('growth_automation_runs')->where('license_id', $site)->latest('created_at')->limit(30)->get(),
            'stages' => DB::table('crm_stages')->where('license_id', $site)->where('archived', false)->whereNotIn('kind', ['won', 'lost'])->orderBy('sort_order')->get(['id', 'name']),
            'members' => DB::table('users')->where('id', $license->user_id)->orWhereIn('id', DB::table('crm_memberships')->where('license_id', $site)->where('status', 'approved')->select('user_id'))->get(['id', 'name']),
        ]);
    }

    public function saveRule(Request $r, string $site, ?string $id = null)
    {
        $this->access->site($r->user(), $site, true);
        $v = $r->validate(['name' => 'required|string|max:120', 'trigger' => ['required', Rule::in(AutomationService::TRIGGERS)],
            'stage_id' => 'required_if:trigger,stage_entered,stale_stage|nullable|string|size:26',
            'delay_minutes' => 'required|integer|min:0|max:525600', 'due_minutes' => 'required|integer|min:1|max:525600',
            'task_title' => 'nullable|string|max:255', 'assigned_to' => 'nullable|integer', 'enabled' => 'required|boolean',
            'version' => ($id ? 'required' : 'nullable').'|integer|min:1']);

        return $this->store->mutate($r, $site, function () use ($r, $site, $id, $v) {
            $this->access->site($r->user()->fresh(), $site, true);
            $this->access->assignee($site, ! empty($v['assigned_to']) ? (int) $v['assigned_to'] : null);
            if (! empty($v['stage_id'])) {
                $stage = $this->store->row('crm_stages', $site, $v['stage_id']);
                abort_if($stage->archived || in_array($stage->kind, ['won', 'lost'], true), 422, 'Выберите действующий этап открытой сделки.');
            }
            $data = $v;
            unset($data['version']);
            if (! in_array($v['trigger'], ['stage_entered', 'stale_stage'], true)) {
                $data['stage_id'] = null;
            }
            $row = $id ? $this->store->update('growth_automation_rules', $this->store->row('growth_automation_rules', $site, $id, true), (int) $v['version'], $data)
                : $this->store->insert('growth_automation_rules', $site, $data);
            $this->store->event($r, $site, 'automation_rules', $row->id, $id ? 'updated' : 'created', ['trigger' => $v['trigger'], 'enabled' => $v['enabled']]);

            return ['success' => true, 'item' => $this->store->present($row)];
        });
    }

    public function metrika(Request $r, string $site)
    {
        $this->access->site($r->user(), $site, true);
        $settings = DB::table('growth_metrika_settings')->where('license_id', $site)->first();
        if ($settings) {
            $settings->has_token = (bool) $settings->oauth_token;
            unset($settings->oauth_token);
        }

        return response()->json(['settings' => $settings,
            'deliveries' => DB::table('growth_metrika_deliveries')->where('license_id', $site)->orderByDesc('created_at')->limit(100)
                ->get(['id', 'deal_id', 'milestone', 'goal', 'status', 'attempts', 'upload_id', 'provider_status', 'error', 'created_at', 'delivered_at']),
            'missing_identifiers' => DB::table('crm_deals')->where('license_id', $site)->whereNotNull('signed_at')->whereNotIn('id', DB::table('service_requests')->where('license_id', $site)->whereNotNull('crm_deal_id')->select('crm_deal_id'))->count(),
        ]);
    }

    public function saveMetrika(Request $r, string $site)
    {
        $this->access->site($r->user(), $site, true);
        $v = $r->validate(['counter_id' => ['required', 'regex:/^[1-9]\d{0,19}$/D'], 'oauth_token' => 'nullable|string|max:2048',
            'signed_goal' => ['nullable', 'regex:/^[a-zA-Z][a-zA-Z0-9_-]{0,119}$/D'], 'paid_goal' => ['nullable', 'regex:/^[a-zA-Z][a-zA-Z0-9_-]{0,119}$/D'],
            'enabled' => 'required|boolean', 'version' => 'nullable|integer|min:1']);

        return $this->store->mutate($r, $site, function () use ($r, $site, $v) {
            $this->access->site($r->user()->fresh(), $site, true);
            $row = DB::table('growth_metrika_settings')->where('license_id', $site)->lockForUpdate()->first();
            if ($row) {
                abort_unless((int) ($v['version'] ?? 0) === (int) $row->version, 409, 'Настройки уже изменены. Обновите страницу.');
            }
            $token = ! empty($v['oauth_token']) ? Crypt::encryptString($v['oauth_token']) : ($row?->oauth_token ?? null);
            abort_unless($token, 422, 'Введите OAuth-токен Метрики.');
            abort_if($v['enabled'] && empty($v['signed_goal']) && empty($v['paid_goal']), 422, 'Укажите хотя бы одну цель.');
            if ($row && $row->counter_id !== $v['counter_id']) {
                abort_if(DB::table('growth_metrika_deliveries')->where('license_id', $site)->whereNotIn('status', ['delivered', 'failed'])->exists(), 409, 'Сначала завершите очередь текущего счётчика.');
            }
            $data = ['counter_id' => $v['counter_id'], 'oauth_token' => $token, 'signed_goal' => $v['signed_goal'] ?? null, 'paid_goal' => $v['paid_goal'] ?? null,
                'enabled' => $v['enabled'], 'enabled_at' => $row?->enabled_at ?: ($v['enabled'] ? now() : null), 'version' => ($row?->version ?? 0) + 1, 'updated_at' => now()];
            DB::table('growth_metrika_settings')->updateOrInsert(['license_id' => $site], $data + ['created_at' => $row?->created_at ?: now()]);
            if ($v['enabled'] && ! empty($v['oauth_token'])) {
                // Credential repair safely rechecks an accepted upload; it never re-uploads its CSV.
                DB::table('growth_metrika_deliveries')->where('license_id', $site)->where('counter_id', $v['counter_id'])
                    ->where('status', 'failed')->whereNotNull('upload_id')->whereIn('error', ['metrika_http_401', 'metrika_http_403'])
                    ->update(['status' => 'uploaded', 'error' => null, 'retry_at' => now(), 'updated_at' => now()]);
            }
            $this->store->event($r, $site, 'metrika_settings', $site, 'updated', ['enabled' => $v['enabled'], 'counter_id' => $v['counter_id']]);

            return ['success' => true];
        });
    }

    public function retryDelivery(Request $r, string $site, string $id)
    {
        $this->access->site($r->user(), $site, true);

        return $this->store->mutate($r, $site, function () use ($r, $site, $id) {
            $this->access->site($r->user()->fresh(), $site, true);
            $row = DB::table('growth_metrika_deliveries')->where('license_id', $site)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless(in_array($row->status, ['failed', 'uncertain'], true), 409, 'Эта доставка уже обрабатывается или завершена.');
            abort_if($row->status === 'uncertain' && ! $r->boolean('confirm_possible_duplicate'), 422, 'Проверьте загрузки Метрики и подтвердите возможный повтор.');
            $settings = DB::table('growth_metrika_settings')->where('license_id', $site)->where('enabled', true)->first();
            abort_unless($settings && $settings->counter_id === $row->counter_id && ($row->upload_id || $settings->{$row->milestone.'_goal'}), 422, 'Включите передачу и задайте цель для счётчика этой конверсии.');
            $recheck = ! empty($row->upload_id);
            DB::table('growth_metrika_deliveries')->where('id', $id)->update(['status' => $recheck ? 'uploaded' : 'queued', 'goal' => $recheck ? $row->goal : $settings->{$row->milestone.'_goal'}, 'upload_id' => $row->upload_id, 'provider_status' => $recheck ? $row->provider_status : null, 'error' => null, 'retry_at' => now(), 'updated_at' => now()]);
            $this->store->event($r, $site, 'metrika_deliveries', $id, $recheck ? 'poll_retry_requested' : 'retry_requested', ['possible_duplicate' => $row->status === 'uncertain']);

            return ['success' => true];
        });
    }
}
