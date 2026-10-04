<?php

declare(strict_types=1);

namespace App\Http\Controllers\Growth;

use App\Http\Controllers\Controller;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmStore;
use App\Services\Growth\OrderPortal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class OrderPortalController extends Controller
{
    public function __construct(private CrmAccess $access, private CrmStore $store, private OrderPortal $portal) {}

    public function manage(Request $r, string $site, string $deal)
    {
        $this->access->site($r->user(), $site);
        $this->store->row('crm_deals', $site, $deal);
        $plan = DB::transaction(fn () => $this->portal->plan($site, $deal));

        return response()->json(['plan' => ['version' => $plan->version, 'document_ids' => json_decode($plan->document_ids, true)], 'milestones' => DB::table('crm_order_milestones')->where('license_id', $site)->where('deal_id', $deal)->orderBy('position')->get(), 'documents' => DB::table('crm_documents')->where('license_id', $site)->where('deal_id', $deal)->get(['id', 'name']), 'access' => DB::table('crm_order_access')->where('license_id', $site)->where('deal_id', $deal)->whereNull('revoked_at')->orderByDesc('created_at')->first(['id', 'expires_at', 'used_at', 'session_expires_at'])]);
    }

    public function save(Request $r, string $site, string $deal)
    {
        $this->access->site($r->user(), $site);
        $v = $r->validate(['version' => 'required|integer|min:1', 'document_ids' => 'present|array|max:100', 'document_ids.*' => ['string', Rule::exists('crm_documents', 'id')->where('license_id', $site)->where('deal_id', $deal)], 'milestones' => 'required|array|size:4', 'milestones.*.id' => ['required', 'distinct', Rule::exists('crm_order_milestones', 'id')->where('license_id', $site)->where('deal_id', $deal)], 'milestones.*.status' => ['required', Rule::in(['planned', 'in_progress', 'completed'])], 'milestones.*.due_at' => 'nullable|date_format:Y-m-d', 'milestones.*.note' => 'nullable|string|max:2000']);

        return $this->store->mutate($r, $site, function () use ($r, $site, $deal, $v) {
            $plan = $this->store->row('crm_order_plans', $site, $deal, true);
            $next = $this->store->update('crm_order_plans', $plan, $v['version'], ['document_ids' => array_values(array_unique($v['document_ids']))]);
            foreach ($v['milestones'] as $m) {
                $old = DB::table('crm_order_milestones')->where('license_id', $site)->where('deal_id', $deal)->where('id', $m['id'])->first();
                DB::table('crm_order_milestones')->where('id', $old->id)->update(['status' => $m['status'], 'due_at' => $m['due_at'] ?? null, 'note' => $m['note'] ?? null, 'completed_at' => $m['status'] === 'completed' ? ($old->completed_at ?? now()) : null, 'updated_at' => now()]);
            }
            $this->store->event($r, $site, 'order_plan', $deal, 'customer_plan_updated', [], null, $deal);

            return ['success' => true, 'version' => $next->version];
        });
    }

    public function invite(Request $r, string $site, string $deal)
    {
        $this->access->site($r->user(), $site);
        $result = $this->store->mutate($r, $site, function () use ($r, $site, $deal) {
            $id = $this->portal->invite($site, $deal, $r->user()->id);
            $this->store->event($r, $site, 'order_access', $id, 'customer_invited', [], null, $deal);

            return ['success' => true, 'id' => $id];
        });
        $row = $this->store->row('crm_order_access', $site, $result['id']);
        abort_if($row->revoked_at || $row->expires_at <= now(), 409, 'Создайте новое приглашение с новым ключом операции.');

        return response()->json($result + ['path' => '/orders/access/'.Crypt::decryptString($row->token_encrypted), 'expires_at' => $row->expires_at]);
    }

    public function revoke(Request $r, string $site, string $deal)
    {
        $this->access->site($r->user(), $site);

        return $this->store->mutate($r, $site, function () use ($r, $site, $deal) {
            $this->store->row('crm_deals', $site, $deal);
            DB::table('crm_order_access')->where('license_id', $site)->where('deal_id', $deal)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            $this->store->event($r, $site, 'order_access', $deal, 'customer_access_revoked', [], null, $deal);

            return ['success' => true];
        });
    }

    public function invitation(Request $r, string $token)
    {
        $v = $r->validate(['domain' => 'required|string|max:253']);
        $access = $this->portal->invitation($token, $v['domain']);
        abort_if($access->used_at, 409, 'Приглашение уже использовано.');

        return response()->json(['expires_at' => $access->expires_at]);
    }

    public function exchange(Request $r)
    {
        $v = $r->validate(['token' => 'required|string|size:64', 'domain' => 'required|string|max:253', 'exchange_key' => 'required|uuid']);

        return response()->json($this->portal->exchange($v['token'], $v['domain'], $v['exchange_key']));
    }

    public function show(Request $r)
    {
        $v = $r->validate(['domain' => 'required|string|max:253']);

        return response()->json($this->portal->customerData($this->portal->session($r->bearerToken(), $v['domain'])));
    }

    public function document(Request $r, string $id)
    {
        $v = $r->validate(['domain' => 'required|string|max:253']);
        $access = $this->portal->session($r->bearerToken(), $v['domain']);
        $plan = DB::table('crm_order_plans')->where('license_id', $access->license_id)->where('id', $access->deal_id)->first();
        abort_unless($plan && in_array($id, json_decode($plan->document_ids, true), true), 404);
        $document = DB::table('crm_documents')->where('license_id', $access->license_id)->where('deal_id', $access->deal_id)->where('id', $id)->first();
        abort_unless($document, 404);
        $bytes = Crypt::decryptString(Storage::disk($document->disk)->get($document->path));
        abort_unless(hash_equals($document->sha256, hash('sha256', $bytes)), 503, 'Документ временно недоступен.');

        return response($bytes)->header('Content-Type', $document->mime)->header('Content-Disposition', 'attachment; filename="document.'.$this->extension($document->mime).'"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', default => 'bin'
        };
    }
}
