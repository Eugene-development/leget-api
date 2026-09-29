<?php

namespace App\Http\Controllers;

use App\Services\University\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class UniversityMediaController extends Controller
{
    public function __construct(private MediaStorage $storage) {}

    public function store(Request $request)
    {
        $data = $request->validate(['filename' => 'required|string|max:255', 'mime' => ['required', Rule::in(array_keys(MediaStorage::LIMITS))], 'size' => 'required|integer|min:1|max:2147483648', 'duration' => 'nullable|integer|min:1|max:43200']);

        return response()->json($this->storage->start($request->user()->id, $data), 201);
    }

    public function action(Request $request, string $id, string $action)
    {
        $row = $this->storage->owned($id, $request->user()->id);
        if ($action === 'part') {
            $data = $request->validate(['number' => 'required|integer|min:1|max:256']);

            return response()->json(['url' => $this->storage->part($row, $data['number'])]);
        }
        if ($action === 'complete') {
            return response()->json($this->storage->complete($row));
        }
        abort_unless($action === 'abort', 404);
        $this->storage->abort($row);

        return response()->json(['status' => 'cancelled']);
    }

    public function show(Request $request, string $id)
    {
        if (! $request->user()->role->can('university.manage')) {
            abort_unless($this->publishedReference($id), 404);
        }

        return response()->json($this->storage->read($id))->header('Cache-Control', 'private, no-store');
    }

    private function publishedReference(string $id): bool
    {
        if (DB::table('university_interviews')->where('status', 'published')->where(fn ($q) => $q->where('cover_id', $id)->orWhere('video_id', $id))->exists()) {
            return true;
        }
        if (DB::table('university_resources')->where('status', 'published')->where(fn ($q) => $q->where('cover_id', $id)->orWhere('file_id', $id))->exists()) {
            return true;
        }
        foreach (DB::table('university_courses')->whereIn('status', ['published', 'archived'])->get(['cover_id', 'curriculum']) as $course) {
            if ($course->cover_id === $id) {
                return true;
            }
            foreach (json_decode($course->curriculum, true)['lessons'] as $lesson) {
                if (($lesson['video_id'] ?? null) === $id) {
                    return true;
                }
            }
        }

        return false;
    }
}
