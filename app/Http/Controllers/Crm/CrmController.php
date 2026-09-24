<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmDefaults;
use App\Services\Crm\CrmStore;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CrmController extends Controller
{
    private const TABLES = ['clients' => 'crm_clients', 'companies' => 'crm_companies', 'deals' => 'crm_deals', 'tasks' => 'crm_tasks', 'stages' => 'crm_stages', 'templates' => 'crm_templates', 'incoming' => 'service_requests', 'members' => 'crm_memberships'];

    public function __construct(private CrmAccess $access, private CrmStore $store) {}

    public function context(Request $r)
    {
        $user = $r->user();
        $sites = DB::table('licenses')->select('id', 'name', 'domain', 'user_id')->where(function ($q) use ($user) {
            if ($user->role?->can('crm.admin')) {
                $q->whereRaw('1=1');
            } else {
                $q->where('user_id', $user->id);
                if ($user->role?->can('crm.work')) {
                    $q->orWhereIn('id', DB::table('crm_memberships')->select('license_id')->where('user_id', $user->id)->where('status', 'approved'));
                }
            }
        })->orderBy('name')->get();
        $domain = strtolower(preg_replace('/^www\./i', '', (string) $r->query('domain', '')));
        $current = DB::table('licenses')->where('domain', $domain)->first(['id', 'name', 'domain']);

        return response()->json(['sites' => $sites->map(fn ($s) => ['id' => $s->id, 'name' => $s->name ?: $s->domain, 'domain' => $s->domain, 'manage' => $this->access->manages($user, $s)]),
            'current_site' => $current, 'role' => $user->role?->value ?? 'client',
            'applications' => DB::table('crm_memberships')->where('user_id', $user->id)->get(['id', 'license_id', 'status', 'review_note', 'version']),
        ]);
    }

    public function dashboard(Request $r, string $site)
    {
        $license = $this->access->site($r->user(), $site);
        $canManage = $this->access->manages($r->user(), $license);
        $members = DB::table('crm_memberships')->join('users', 'users.id', '=', 'crm_memberships.user_id')
            ->where('license_id', $site)->where('status', 'approved')->where('users.role', 'manager')->get(['users.id', 'users.name']);
        $owner = DB::table('users')->where('id', $license->user_id)->first(['id', 'name']);
        if ($owner) {
            $members->push($owner);
        }

        return response()->json([
            'manage' => $canManage,
            'stages' => $this->store->query('crm_stages', $site)->orderBy('sort_order')->get(),
            'members' => $members->unique('id')->values(),
            'settings' => $this->store->query('crm_settings', $site)->first(),
            'counts' => [
                'incoming' => $this->incoming($site, $canManage)->where('status', 'new')->count(),
                'deals' => $this->store->query('crm_deals', $site)->whereNull('closed_at')->count(),
                'overdue' => $this->store->query('crm_tasks', $site)->whereNull('completed_at')->where('due_at', '<', now())->count(),
                'my_tasks' => $this->store->query('crm_tasks', $site)->where('assigned_to', $r->user()->id)->whereNull('completed_at')->count(),
                'pending_members' => $canManage ? $this->store->query('crm_memberships', $site)->where('status', 'pending')->count() : 0,
            ],
        ]);
    }

    public function initialize(Request $r, string $site)
    {
        $this->access->site($r->user(), $site, true);

        return $this->store->mutate($r, $site, function () use ($r, $site) {
            $this->access->site($r->user()->fresh(), $site, true);
            app(CrmDefaults::class)->seed($site);

            return ['success' => true];
        });
    }

    public function index(Request $r, string $site, string $resource)
    {
        $license = $this->access->site($r->user(), $site, $resource === 'members');
        abort_unless(isset(self::TABLES[$resource]), 404);
        $v = $r->validate(['options' => 'nullable|boolean', 'search' => 'nullable|string|max:255', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100', 'assigned_to' => 'nullable|integer', 'stage_id' => 'nullable|string|max:26', 'client_id' => 'nullable|string|max:26', 'source' => 'nullable|string|max:32', 'status' => 'nullable|string|max:24', 'overdue' => 'nullable|boolean']);
        $q = $resource === 'incoming' ? $this->incoming($site, $this->access->manages($r->user(), $license)) : $this->store->query(self::TABLES[$resource], $site);
        if (! empty($v['search'])) {
            $cols = match ($resource) {
                'clients' => ['name', 'phone', 'email', 'contact_name'], 'companies' => ['name', 'inn', 'contact_name'],
                'incoming' => ['name', 'phone', 'email', 'message'], 'deals', 'tasks' => ['title'], 'stages', 'templates' => ['name'], default => [],
            };
            if ($cols) {
                $q->where(function ($inner) use ($cols, $v) {
                    foreach ($cols as $c) {
                        $inner->orWhere($c, 'like', '%'.$v['search'].'%');
                    }
                });
            }
        }
        if (isset($v['assigned_to']) && in_array($resource, ['incoming', 'deals', 'tasks'])) {
            $q->where('assigned_to', $v['assigned_to']);
        }
        if (! empty($v['stage_id']) && $resource === 'deals') {
            $q->where('stage_id', $v['stage_id']);
        }
        if (! empty($v['client_id']) && in_array($resource, ['deals', 'tasks'])) {
            $q->where('client_id', $v['client_id']);
        }
        if (! empty($v['source']) && $resource === 'deals') {
            $q->where('source', $v['source']);
        }
        if (! empty($v['status']) && in_array($resource, ['incoming', 'members'])) {
            $q->where('status', $v['status']);
        }
        if (! empty($v['overdue']) && in_array($resource, ['deals', 'tasks'])) {
            $q->where('due_at', '<', now())->whereNull($resource === 'tasks' ? 'completed_at' : 'closed_at');
        }
        if ($resource === 'stages') {
            $q->orderBy('sort_order');
        }
        // Selects need labels, not histories, template bodies or a COUNT query.
        if (! empty($v['options'])) {
            $columns = match ($resource) {
                'clients', 'companies' => ['id', 'name'],
                'deals' => ['id', 'title'],
                'templates' => ['id', 'name', 'published_version'],
                default => null,
            };
            abort_unless($columns, 422, 'Для этого раздела нет списка выбора.');

            return response()->json(['items' => $q->orderByDesc('created_at')->orderByDesc('id')->limit((int) ($v['per_page'] ?? 30))->get($columns)]);
        }
        if ($resource === 'deals') {
            // One query for the visible names, scoped to the same site. No whole-client-directory fetch.
            $q->select('crm_deals.*')->selectSub(
                DB::table('crm_clients')->select('name')->whereColumn('crm_clients.id', 'crm_deals.client_id')->where('crm_clients.license_id', $site),
                'client_name'
            );
        }
        $page = $q->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($v['per_page'] ?? 30));
        $page->through(function ($row) use ($resource) {
            $item = $this->store->present($row);
            if ($resource === 'members') {
                $item['user'] = DB::table('users')->where('id', $row->user_id)->first(['id', 'name', 'email']);
            }

            return $item;
        });

        return response()->json(['items' => $page]);
    }

    public function show(Request $r, string $site, string $resource, string $id)
    {
        $license = $this->access->site($r->user(), $site, $resource === 'members');
        abort_unless(isset(self::TABLES[$resource]), 404);
        $row = $this->store->row(self::TABLES[$resource], $site, $id);
        if ($resource === 'incoming' && $row->service_type === 'manager-access') {
            $this->access->site($r->user(), $site, true);
        }
        $result = ['item' => $this->store->present($row)];
        if ($resource === 'members') {
            $result['item']['user'] = DB::table('users')->where('id', $row->user_id)->first(['id', 'name', 'email']);
        }
        if ($resource === 'clients' || $resource === 'deals') {
            $client = $resource === 'clients' ? $id : $row->client_id;
            $events = $this->store->query('crm_events', $site)->where($resource === 'clients' ? 'client_id' : 'deal_id', $id);
            $result['history'] = $events->orderByDesc('created_at')->orderByDesc('id')->paginate(50, ['*'], 'history_page')->through(fn ($v) => $this->store->present($v));
            $result['incoming'] = $this->incoming($site, $this->access->manages($r->user(), $license))->where($resource === 'clients' ? 'crm_client_id' : 'crm_deal_id', $id)->orderByDesc('created_at')->limit(100)->get()->map(fn ($v) => $this->store->present($v));
            $result['tasks'] = $this->store->query('crm_tasks', $site)->where($resource === 'clients' ? 'client_id' : 'deal_id', $id)->orderBy('due_at')->limit(100)->get()->map(fn ($v) => $this->store->present($v));
            if ($resource === 'clients') {
                $result['documents'] = $this->store->query('crm_documents', $site)->whereIn('deal_id', $this->store->query('crm_deals', $site)->where('client_id', $id)->select('id'))->latest()->limit(100)->get()->map(fn ($v) => $this->store->present($v));
                $result['deals'] = $this->store->query('crm_deals', $site)->where('client_id', $id)->latest()->limit(100)->get()->map(fn ($v) => $this->store->present($v));
            } else {
                $result['client'] = $this->store->present($this->store->row('crm_clients', $site, $client));
                $result['companies'] = $this->store->query('crm_deal_companies', $site)->where('deal_id', $id)->get()->map(function ($v) use ($site) {
                    return (array) $v + ['company' => $this->store->row('crm_companies', $site, $v->company_id)];
                });
                $result['payments'] = $this->store->query('crm_payments', $site)->where('deal_id', $id)->orderBy('paid_at')->get();
                $result['paid_total'] = $this->paidTotal($site, $id);
                $result['documents'] = $this->store->query('crm_documents', $site)->where('deal_id', $id)->latest()->get()->map(fn ($v) => $this->store->present($v));
            }
        }
        if ($resource === 'incoming') {
            $result['attachments'] = DB::table('service_request_attachments')->where('service_request_id', $id)->get(['id', 'name', 'mime', 'size']);
        }

        return response()->json($result);
    }

    public function duplicates(Request $r, string $site)
    {
        $this->access->site($r->user(), $site);
        $v = $r->validate(['phone' => 'nullable|string|max:50', 'email' => 'nullable|email|max:255']);
        $phone = $this->phone($v['phone'] ?? '');
        $email = strtolower(trim($v['email'] ?? ''));
        $q = $this->store->query('crm_clients', $site)->where(function ($q) use ($phone, $email) {
            $q->whereRaw('1=0');
            if ($phone !== '') {
                $q->orWhere('phone_normalized', $phone);
            }
            if ($email !== '') {
                $q->orWhere('email_normalized', $email);
            }
        });

        return response()->json(['items' => $q->limit(20)->get(['id', 'name', 'phone', 'email'])]);
    }

    public function partners(Request $r, string $site)
    {
        $this->access->site($r->user(), $site);
        $v = $r->validate(['search' => 'nullable|string|max:255']);

        // Business directory only; never expose partner users, applications or CRM history.
        return response()->json(['items' => DB::table('partner_profiles')->where('status', 'approved')
            ->when(! empty($v['search']), fn ($q) => $q->where('company', 'like', '%'.$v['search'].'%'))
            ->limit(100)->get(['id', 'company', 'inn', 'partner_type', 'city', 'website'])]);
    }

    public function save(Request $r, string $site, string $resource, ?string $id = null)
    {
        $this->access->site($r->user(), $site, in_array($resource, ['stages', 'templates']));
        abort_unless(in_array($resource, ['clients', 'companies', 'deals', 'tasks', 'stages']), 404);

        return $this->store->mutate($r, $site, function () use ($r, $site, $resource, $id) {
            if ($resource === 'stages') {
                $this->access->site($r->user()->fresh(), $site, true);
            }
            $row = $id ? $this->store->row(self::TABLES[$resource], $site, $id, true) : null;
            $rules = match ($resource) {
                'clients' => ['kind' => 'required|in:person,organization', 'name' => 'required|string|max:255', 'contact_name' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:50', 'email' => 'nullable|email|max:255', 'city' => 'nullable|string|max:120', 'address' => 'nullable|string|max:2000', 'requisites' => 'nullable|array', 'requisites.inn' => 'nullable|string|max:12', 'requisites.bank' => 'nullable|string|max:255', 'requisites.account' => 'nullable|string|max:34'],
                'companies' => ['name' => 'required|string|max:255', 'inn' => 'nullable|digits_between:10,12', 'contact_name' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:50', 'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:5000', 'partner_profile_id' => 'nullable|string|size:26'],
                'deals' => ['client_id' => 'required|string|size:26', 'title' => 'required|string|max:255', 'assigned_to' => 'nullable|integer', 'amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'], 'due_at' => 'nullable|date_format:Y-m-d', 'next_action_at' => 'nullable|date_format:Y-m-d', 'description' => 'nullable|string|max:10000', 'contract_number' => 'nullable|string|max:100', 'contract_date' => 'nullable|date_format:Y-m-d', 'signed_at' => 'nullable|date_format:Y-m-d', 'accepted_at' => 'nullable|date_format:Y-m-d', 'specification' => 'nullable|array|max:100', 'specification.*' => 'required|array:name,quantity,price', 'specification.*.name' => 'required|string|max:500', 'specification.*.quantity' => 'required|integer|min:1|max:100000', 'specification.*.price' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/']],
                'tasks' => ['title' => 'required|string|max:255', 'kind' => 'required|in:task,call,meeting,email', 'assigned_to' => 'required|integer', 'due_at' => 'required|date', 'client_id' => 'nullable|string|size:26', 'deal_id' => 'nullable|string|size:26'],
                'stages' => ['name' => 'required|string|max:120', 'sort_order' => 'required|integer|min:0|max:10000'],
            };
            if ($row) {
                $rules['version'] = 'required|integer|min:1';
            }
            $data = $r->validate($rules);
            $version = (int) ($data['version'] ?? 1);
            unset($data['version']);
            if ($resource === 'clients') {
                $data['phone_normalized'] = $this->phone($data['phone'] ?? '');
                $data['email_normalized'] = strtolower(trim($data['email'] ?? ''));
                if (isset($data['requisites'])) {
                    $data['requisites'] = array_intersect_key($data['requisites'], array_flip(['inn', 'bank', 'account']));
                }
            }
            if ($resource === 'companies' && ! empty($data['partner_profile_id'])) {
                abort_unless(DB::table('partner_profiles')->where('id', $data['partner_profile_id'])->where('status', 'approved')->exists(), 422, 'Партнёр не одобрен.');
            }
            if (! empty($data['client_id'])) {
                $this->store->row('crm_clients', $site, $data['client_id']);
            }
            if (! empty($data['deal_id'])) {
                $deal = $this->store->row('crm_deals', $site, $data['deal_id']);
                abort_if(! empty($data['client_id']) && $data['client_id'] !== $deal->client_id, 422, 'Клиент и сделка не совпадают.');
                $data['client_id'] = $deal->client_id;
            }
            if (array_key_exists('assigned_to', $data)) {
                $this->access->assignee($site, $data['assigned_to'] ? (int) $data['assigned_to'] : null);
            }
            if ($resource === 'tasks') {
                $data['due_at'] = Carbon::parse($data['due_at'], 'Europe/Moscow')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            }
            if ($resource === 'deals') {
                if ($row) {
                    abort_if($row->closed_at, 409, 'Сначала повторно откройте сделку с основанием.');
                    abort_if($data['client_id'] !== $row->client_id, 422, 'Клиента существующей сделки менять нельзя: история уже связана с ним.');
                    $stage = $this->store->row('crm_stages', $site, $row->stage_id);
                    $this->checkMilestones((object) array_replace((array) $row, $data), $stage->kind);
                } else {
                    $stage = $this->store->query('crm_stages', $site)->where('system_key', 'lead')->first();
                    abort_unless($stage, 422, 'Владелец должен настроить воронку.');
                    $data['stage_id'] = $stage->id;
                }
            }
            $saved = $row ? $this->store->update(self::TABLES[$resource], $row, $version, $data) : $this->store->insert(self::TABLES[$resource], $site, $data);
            $client = $resource === 'clients' ? $saved->id : ($saved->client_id ?? null);
            $deal = $resource === 'deals' ? $saved->id : ($saved->deal_id ?? null);
            $this->store->event($r, $site, $resource, $saved->id, $row ? 'updated' : 'created', ['before' => $row ? $this->store->present($row) : null, 'after' => $data], $client, $deal);

            return ['success' => true, 'item' => $this->store->present($saved)];
        });
    }

    public function action(Request $r, string $site, string $resource, string $id, string $action)
    {
        $this->access->site($r->user(), $site, in_array($resource, ['members', 'stages']));
        abort_unless(isset(self::TABLES[$resource]), 404);

        return $this->store->mutate($r, $site, function () use ($r, $site, $resource, $id, $action) {
            if (in_array($resource, ['members', 'stages'])) {
                $this->access->site($r->user()->fresh(), $site, true);
            }
            $row = $this->store->row(self::TABLES[$resource], $site, $id, true);
            $v = $r->validate(['version' => 'required|integer|min:1']);
            abort_unless((int) $row->version === (int) $v['version'], 409, 'Запись уже изменена. Обновите карточку.');
            $data = [];
            $eventData = [];
            $client = $row->client_id ?? ($resource === 'clients' ? $id : null);
            $dealId = $resource === 'deals' ? $id : ($row->deal_id ?? null);
            if ($resource === 'clients' && $action === 'rate') {
                $data = $r->validate(['rating' => 'required|integer|min:1|max:10', 'rating_comment' => 'required|string|min:3|max:2000']);
                $eventData['previous_rating'] = $row->rating;
            } elseif ($resource === 'deals' && $action === 'stage') {
                $input = $r->validate(['stage_id' => 'required|string|size:26', 'reason' => 'nullable|string|min:3|max:2000']);
                $stage = $this->store->row('crm_stages', $site, $input['stage_id']);
                abort_if($stage->archived, 422, 'Этап архивирован.');
                if ($row->closed_at) {
                    abort_if(empty($input['reason']), 422, 'Для повторного открытия укажите причину.');
                }
                if ($stage->kind === 'lost') {
                    abort_if(empty($input['reason']), 422, 'Укажите причину отказа.');
                }
                $this->checkMilestones($row, $stage->kind);
                if ($stage->kind === 'won') {
                    abort_if(bccomp($this->paidTotal($site, $id), (string) $row->amount, 2) < 0, 422, 'Договор оплачен не полностью.');
                }
                $data = ['stage_id' => $stage->id, 'closed_at' => in_array($stage->kind, ['won', 'lost']) ? now() : null, 'loss_reason' => $stage->kind === 'lost' ? $input['reason'] : null, 'paused' => false, 'pause_reason' => null];
                $eventData = ['previous_stage' => $row->stage_id, 'reason' => $input['reason'] ?? null];
            } elseif ($resource === 'deals' && $action === 'pause') {
                abort_if($row->closed_at, 422, 'Закрытая сделка не приостанавливается.');
                $input = $r->validate(['paused' => 'required|boolean', 'reason' => 'required_if:paused,true|string|min:3|max:2000', 'next_action_at' => 'required_if:paused,true|nullable|date_format:Y-m-d']);
                $data = ['paused' => $input['paused'], 'pause_reason' => $input['paused'] ? $input['reason'] : null, 'next_action_at' => $input['next_action_at'] ?? null];
            } elseif ($resource === 'deals' && $action === 'payment') {
                abort_if($row->closed_at, 409, 'Перед исправлением расчётов повторно откройте сделку.');
                $input = $r->validate(['kind' => 'required|in:payment,refund', 'amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'], 'paid_at' => 'required|date_format:Y-m-d', 'comment' => 'required|string|min:3|max:2000']);
                abort_if(bccomp($input['amount'], '0', 2) <= 0, 422, 'Сумма должна быть положительной.');
                abort_if($input['kind'] === 'refund' && bccomp($input['amount'], $this->paidTotal($site, $id), 2) > 0, 422, 'Возврат превышает полученные платежи.');
                $payment = $this->store->insert('crm_payments', $site, $input + ['deal_id' => $id, 'created_by' => $r->user()->id]);
                $eventData = ['payment' => $this->store->present($payment)];
            } elseif ($resource === 'deals' && $action === 'company') {
                $input = $r->validate(['company_id' => 'required|string|size:26', 'role' => 'required|string|max:120', 'contact_name' => 'nullable|string|max:255', 'responsibility' => 'nullable|string|max:2000', 'due_at' => 'nullable|date_format:Y-m-d', 'status' => 'required|in:planned,working,completed,cancelled']);
                $this->store->row('crm_companies', $site, $input['company_id']);
                $existing = $this->store->query('crm_deal_companies', $site)->where('deal_id', $id)->where('company_id', $input['company_id'])->where('role', $input['role'])->first();
                $participant = $existing ? $this->store->update('crm_deal_companies', $existing, $existing->version, $input) : $this->store->insert('crm_deal_companies', $site, $input + ['deal_id' => $id]);
                $eventData = ['participant' => $this->store->present($participant)];
            } elseif ($resource === 'tasks' && $action === 'complete') {
                $input = $r->validate(['completed' => 'required|boolean']);
                $data = ['completed_at' => $input['completed'] ? now() : null];
            } elseif ($resource === 'incoming' && $action === 'process') {
                if ($row->service_type === 'manager-access') {
                    $this->access->site($r->user()->fresh(), $site, true);
                }
                $data = $r->validate(['status' => 'required|in:new,processed,completed,cancelled', 'assigned_to' => 'nullable|integer']);
                $this->access->assignee($site, ! empty($data['assigned_to']) ? (int) $data['assigned_to'] : null);
            } elseif ($resource === 'incoming' && $action === 'convert') {
                abort_if($row->service_type === 'manager-access', 422, 'Запрос допуска не является продажей.');
                if ($row->crm_deal_id) {
                    return ['success' => true, 'deal_id' => $row->crm_deal_id, 'item' => $this->store->present($row)];
                }
                $input = $r->validate(['client_id' => 'required_without:deal_id|nullable|string|size:26', 'deal_id' => 'nullable|string|size:26', 'title' => 'required_without:deal_id|nullable|string|max:255', 'assigned_to' => 'nullable|integer']);
                if (! empty($input['deal_id'])) {
                    $deal = $this->store->row('crm_deals', $site, $input['deal_id']);
                } else {
                    $this->store->row('crm_clients', $site, $input['client_id']);
                    $this->access->assignee($site, ! empty($input['assigned_to']) ? (int) $input['assigned_to'] : null);
                    $stage = $this->store->query('crm_stages', $site)->where('system_key', 'lead')->first();
                    abort_unless($stage, 422, 'Воронка не настроена.');
                    $deal = $this->store->insert('crm_deals', $site, ['client_id' => $input['client_id'], 'stage_id' => $stage->id, 'title' => $input['title'], 'assigned_to' => $input['assigned_to'] ?? null, 'source' => $row->channel]);
                }
                $data = ['crm_deal_id' => $deal->id, 'crm_client_id' => $deal->client_id, 'status' => 'completed'];
                $client = $deal->client_id;
                $dealId = $deal->id;
            } elseif ($resource === 'members' && in_array($action, ['approve', 'reject', 'revoke'])) {
                // User lock serializes approvals on different sites and protects the global role.
                $user = User::whereKey($row->user_id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($user->role, [Role::Client, Role::Manager]), 409, 'Существующую роль пользователя нельзя заменить на менеджера.');
                $input = $r->validate(['reason' => ($action === 'approve' ? 'nullable' : 'required').'|string|max:2000']);
                $data = ['status' => match ($action) {
                    'approve' => 'approved', 'reject' => 'rejected', default => 'revoked'
                }, 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'review_note' => $input['reason'] ?? null];
                $otherApproved = DB::table('crm_memberships')->where('user_id', $user->id)->where('id', '!=', $id)->where('status', 'approved')->lockForUpdate()->get()->isNotEmpty();
                $user->forceFill(['role' => $action === 'approve' || $otherApproved ? Role::Manager : Role::Client])->save();
            } elseif ($resource === 'stages' && $action === 'archive') {
                abort_if($row->system_key !== null, 422, 'Системный этап можно переименовать или переместить, но нельзя архивировать.');
                $input = $r->validate(['replacement_id' => 'nullable|string|size:26']);
                $deals = $this->store->query('crm_deals', $site)->where('stage_id', $id)->whereNull('closed_at')->get();
                if ($deals->isNotEmpty()) {
                    abort_if(empty($input['replacement_id']), 422, 'Выберите этап для переноса открытых сделок.');
                    $replacement = $this->store->row('crm_stages', $site, $input['replacement_id']);
                    abort_if($replacement->archived || $replacement->id === $id || $replacement->kind !== 'open', 422, 'Выберите другой действующий промежуточный этап.');
                    foreach ($deals as $deal) {
                        $this->store->update('crm_deals', $deal, $deal->version, ['stage_id' => $replacement->id]);
                        $this->store->event($r, $site, 'deals', $deal->id, 'stage_transferred', ['from' => $id, 'to' => $replacement->id], $deal->client_id, $deal->id);
                    }
                }
                $data = ['archived' => true];
            } elseif (in_array($resource, ['clients', 'deals']) && $action === 'note') {
                $eventData = $r->validate(['note' => 'required|string|min:1|max:10000']);
            } else {
                abort(404);
            }
            $saved = $this->store->update(self::TABLES[$resource], $row, (int) $v['version'], $data);
            $this->store->event($r, $site, $resource, $id, $action, $eventData + $data, $client, $dealId);

            return ['success' => true, 'item' => $this->store->present($saved), 'deal_id' => $dealId];
        });
    }

    public function settings(Request $r, string $site)
    {
        $this->access->site($r->user(), $site, true);

        return $this->store->mutate($r, $site, function () use ($r, $site) {
            $this->access->site($r->user()->fresh(), $site, true);
            $v = $r->validate(['version' => 'required|integer|min:1', 'requisites' => 'required|array:name,inn,kpp,ogrn,address,bank,bik,account,correspondent,representative', 'requisites.*' => 'nullable|string|max:1000']);
            $changed = $this->store->query('crm_settings', $site)->where('version', $v['version'])->update(['requisites' => json_encode($v['requisites'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'version' => $v['version'] + 1, 'updated_at' => now()]);
            abort_unless($changed, 409, 'Реквизиты уже изменены.');
            $this->store->event($r, $site, 'settings', $site, 'updated', $v['requisites']);

            return ['success' => true];
        });
    }

    public function unassigned(Request $r)
    {
        abort_unless($r->user()->role?->can('crm.admin'), 403);

        return response()->json(['items' => DB::table('service_requests')->whereNull('license_id')->latest()->paginate(30)->through(fn ($row) => $this->store->present($row))]);
    }

    public function assignRequest(Request $r, string $site, string $id)
    {
        abort_unless($r->user()->role?->can('crm.admin'), 403);
        $this->access->site($r->user(), $site);

        return $this->store->mutate($r, $site, function () use ($r, $site, $id) {
            $v = $r->validate(['reason' => 'required|string|min:3|max:2000', 'version' => 'required|integer|min:1']);
            $count = DB::table('service_requests')->where('id', $id)->whereNull('license_id')->where('version', $v['version'])
                ->update(['license_id' => $site, 'version' => $v['version'] + 1, 'updated_at' => now()]);
            abort_unless($count, 409, 'Заявка уже распределена или изменена.');
            $this->store->event($r, $site, 'incoming', $id, 'assigned_to_site', ['reason' => $v['reason']]);

            return ['success' => true];
        });
    }

    private function incoming(string $site, bool $manage)
    {
        return $this->store->query('service_requests', $site)->when(! $manage, fn ($q) => $q->where('service_type', '!=', 'manager-access'));
    }

    private function phone(string $value): string
    {
        $phone = preg_replace('/\D/', '', $value);
        if (strlen($phone) === 11 && str_starts_with($phone, '8')) {
            $phone = '7'.substr($phone, 1);
        }

        return $phone;
    }

    private function paidTotal(string $site, string $id): string
    {
        $total = '0.00';
        foreach ($this->store->query('crm_payments', $site)->where('deal_id', $id)->get() as $p) {
            $total = $p->kind === 'payment' ? bcadd($total, (string) $p->amount, 2) : bcsub($total, (string) $p->amount, 2);
        }

        return $total;
    }

    private function checkMilestones(object $deal, string $kind): void
    {
        if (in_array($kind, ['signed', 'execution', 'acceptance', 'won'])) {
            abort_if(empty($deal->signed_at) || empty($deal->contract_number) || empty($deal->contract_date), 422, 'Укажите номер, дату договора и дату подписания.');
        }
        if ($kind === 'won') {
            abort_if(empty($deal->accepted_at), 422, 'Подтвердите приёмку работ или поставки.');
        }
    }
}
