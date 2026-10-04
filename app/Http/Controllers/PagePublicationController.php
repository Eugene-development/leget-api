<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PagePublicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PagePublicationController extends Controller
{
    public function __construct(private PagePublicationService $publication) {}

    public function state(Request $request, string $site): JsonResponse
    {
        $data = $request->validate(['slug' => ['required', 'string', 'max:255']]);

        return $this->respond($this->publication->state($request->user(), $site, $data['slug']));
    }

    public function begin(Request $request, string $site): JsonResponse
    {
        $data = $request->validate(['slug' => ['required', 'string', 'max:255']]);

        return $this->respond($this->publication->begin($request->user(), $site, $data['slug']));
    }

    public function edit(Request $request, string $site, string $draft): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:component,reset,layout,seo,move'],
            'scope' => ['sometimes', 'in:page,global'],
            'type' => ['required_if:kind,component,layout,move', 'string', 'max:100'],
            'data' => ['present_if:kind,component,layout', 'array'],
            'componentId' => ['required_if:kind,reset', 'string', 'size:26'],
            'title' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'keywords' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'direction' => ['required_if:kind,move', 'in:up,down'],
        ]);
        if ($data['kind'] === 'layout' && ! in_array($data['type'], ['Header', 'Footer'], true)) {
            abort(422);
        }

        return $this->respond($this->publication->edit($request->user(), $site, $draft, (int) $data['version'], $data));
    }

    public function action(Request $request, string $site, string $draft, string $action): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $args = [$request->user(), $site, $draft, (int) $data['version']];
        if ($action === 'discard') {
            $this->publication->discard(...$args);

            return $this->respond(['discarded' => true]);
        }

        return $this->respond($action === 'publish' ? $this->publication->publish(...$args) : $this->publication->preview(...$args));
    }

    public function restore(Request $request, string $site, string $revision): JsonResponse
    {
        $data = $request->validate(['liveHash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);

        return $this->respond($this->publication->restore($request->user(), $site, $revision, $data['liveHash']));
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
