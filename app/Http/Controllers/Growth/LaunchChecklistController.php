<?php

declare(strict_types=1);

namespace App\Http\Controllers\Growth;

use App\Http\Controllers\Controller;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmStore;
use App\Services\Growth\LaunchChecklist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class LaunchChecklistController extends Controller
{
    public function __construct(private CrmAccess $access, private CrmStore $store, private LaunchChecklist $checks) {}

    public function show(Request $r, string $site)
    {
        return response()->json($this->checks->read($this->access->site($r->user(), $site, true)));
    }

    public function update(Request $r, string $site)
    {
        $this->access->site($r->user(), $site, true);
        $v = $r->validate(['check_key' => ['required', Rule::in(LaunchChecklist::MANUAL)], 'checked' => 'required|boolean']);

        return $this->store->mutate($r, $site, function () use ($r, $site, $v) {
            $license = $this->access->site($r->user()->fresh(), $site, true);
            if ($v['checked']) {
                DB::table('site_launch_checks')->updateOrInsert(['license_id' => $site, 'check_key' => $v['check_key']], ['id' => (string) Str::ulid(), 'checked_by' => $r->user()->id, 'checked_at' => now()]);
            } else {
                DB::table('site_launch_checks')->where('license_id', $site)->where('check_key', $v['check_key'])->delete();
            }
            $this->store->event($r, $site, 'launch', $site, 'launch_check_updated', $v);

            return ['success' => true] + $this->checks->read($license);
        });
    }
}
