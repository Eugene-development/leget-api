<?php

declare(strict_types=1);

namespace App\Http\Controllers\Growth;

use App\Http\Controllers\Controller;
use App\Services\Crm\CrmAccess;
use App\Services\Growth\EstimateCalculator;
use App\Services\Growth\SelectionsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EstimateController extends Controller
{
    public function publicSettings(Request $request, SelectionsService $sites)
    {
        $site = $sites->site($request->validate(['domain' => 'required|string|max:253|regex:/^[a-zA-Z0-9.-]+$/'])['domain']);
        $row = DB::table('kitchen_estimate_settings')->where('license_id', $site->id)->where('enabled', true)->first();
        if (! $row) {
            return response()->json(['available' => false]);
        }
        $rules = json_decode($row->rules, true, flags: JSON_THROW_ON_ERROR);

        return response()->json(['available' => true, 'version' => $row->version, 'materials' => array_map(fn ($m) => ['key' => $m['key'], 'label' => $m['label']], $rules['materials']), 'note' => $rules['note'] ?? ''])->header('Cache-Control', 'no-store');
    }

    public function calculate(Request $request, SelectionsService $sites)
    {
        $site = $sites->site($request->validate(['domain' => 'required|string|max:253|regex:/^[a-zA-Z0-9.-]+$/'])['domain']);
        $row = DB::table('kitchen_estimate_settings')->where('license_id', $site->id)->where('enabled', true)->first();
        abort_unless($row, 422, 'Владелец сайта ещё не настроил расчёт.');
        $input = $request->validate(['inputs' => 'required|array:run_cm,layout,material,equipment']);
        $result = EstimateCalculator::calculate(json_decode($row->rules, true, flags: JSON_THROW_ON_ERROR), $input['inputs']);

        return response()->json($result + ['version' => $row->version])->header('Cache-Control', 'no-store');
    }

    public function settings(Request $request, string $site, CrmAccess $access)
    {
        $access->site($request->user(), $site, true);
        $row = DB::table('kitchen_estimate_settings')->where('license_id', $site)->first();

        return response()->json(['enabled' => (bool) ($row->enabled ?? false), 'version' => $row->version ?? null, 'rules' => $row ? json_decode($row->rules, true, flags: JSON_THROW_ON_ERROR) : null])->header('Cache-Control', 'private, no-store');
    }

    public function saveSettings(Request $request, string $site, CrmAccess $access)
    {
        $access->site($request->user(), $site, true);
        $data = $request->validate(['enabled' => 'required|boolean', 'expected_version' => 'nullable|uuid', 'save_key' => 'required|uuid', 'rules' => 'required|array:base_min,base_max,equipment_min,equipment_max,layouts,materials,note']);
        $rules = EstimateCalculator::rules($data['rules']);
        $hash = hash('sha256', json_encode([(bool) $data['enabled'], $rules, $data['expected_version'] ?? null], JSON_THROW_ON_ERROR));
        $version = DB::transaction(function () use ($site, $data, $rules, $hash) {
            // Lock the parent to serialize the first save, when no settings row exists yet.
            DB::table('licenses')->where('id', $site)->lockForUpdate()->first();
            $current = DB::table('kitchen_estimate_settings')->where('license_id', $site)->lockForUpdate()->first();
            if ($current && $current->last_save_key === $data['save_key']) {
                abort_unless(hash_equals($current->last_payload_hash ?? '', $hash), 409, 'Ключ уже использован для других правил.');

                return $current->version;
            }
            abort_unless(($current->version ?? null) === ($data['expected_version'] ?? null), 409, 'Правила изменились в другой вкладке. Обновите страницу перед сохранением.');
            $version = (string) Str::uuid();
            DB::table('kitchen_estimate_settings')->upsert([['license_id' => $site, 'enabled' => $data['enabled'], 'version' => $version, 'last_save_key' => $data['save_key'], 'last_payload_hash' => $hash, 'rules' => json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]], ['license_id'], ['enabled', 'version', 'last_save_key', 'last_payload_hash', 'rules', 'updated_at']);

            return $version;
        });

        return response()->json(['success' => true, 'version' => $version]);
    }
}
