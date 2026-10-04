<?php

declare(strict_types=1);

namespace App\Http\Controllers\Growth;

use App\Http\Controllers\Controller;
use App\Services\Growth\SelectionsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SelectionsController extends Controller
{
    public function catalog(Request $request, SelectionsService $service)
    {
        return response()->json(['items' => array_values($service->catalog($service->site($this->domain($request))))])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, SelectionsService $service)
    {
        $data = $request->validate([
            'title' => 'required|string|min:1|max:160', 'creation_key' => 'required|uuid',
            'items' => 'required|array|min:1|max:30', 'items.*' => 'required|array:kind,id',
            'items.*.kind' => ['required', Rule::in(['project', 'material'])],
            'items.*.id' => 'required|string|max:80',
        ]);
        $token = $request->header('X-Selection-Edit-Token', '');
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token), 422, 'Не удалось подтвердить право управления.');

        return response()->json($service->create($service->site($this->domain($request)), $data, $token), 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $token, SelectionsService $service)
    {
        return response()->json($service->show($service->site($this->domain($request)), $token, $request->header('X-Selection-Edit-Token')))->header('Cache-Control', 'private, no-store');
    }

    public function comment(Request $request, string $token, SelectionsService $service)
    {
        $data = $request->validate(['submission_key' => 'required|uuid', 'author' => 'required|string|min:1|max:80', 'body' => 'required|string|min:1|max:2000']);

        return response()->json(['id' => $service->comment($service->site($this->domain($request)), $token, $data)], 201)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, string $token, SelectionsService $service)
    {
        $service->revoke($service->site($this->domain($request)), $token, $request->header('X-Selection-Edit-Token', ''));

        return response()->json(['success' => true])->header('Cache-Control', 'no-store');
    }

    private function domain(Request $request): string
    {
        return $request->validate(['domain' => 'required|string|max:253|regex:/^[a-zA-Z0-9.-]+$/'])['domain'];
    }
}
