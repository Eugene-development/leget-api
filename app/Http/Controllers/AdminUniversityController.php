<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\University\Content;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AdminUniversityController extends Controller
{
    public function __construct(private Content $content) {}

    public function index(string $kind)
    {
        return response()->json(['items' => DB::table($this->content->table($kind))->orderBy('position')->get()->map(fn ($r) => $this->content->present($r))]);
    }

    public function save(Request $request, string $kind, ?string $slug = null)
    {
        $table = $this->content->table($kind);
        $id = $slug ?? $request->validate(['slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique($table, 'slug')]])['slug'];
        $item = DB::transaction(function () use ($request, $kind, $table, $id, $slug) {
            $old = $slug ? DB::table($table)->where('slug', $slug)->lockForUpdate()->first() : null;
            abort_if($slug && ! $old, 404);
            // Lock shared with assessment: a first attempt cannot race a curriculum edit.
            if ($old) {
                $request->validate(['updated_at' => 'required|string']);
                abort_unless($request->string('updated_at')->toString() === $old->updated_at, 409, 'Материал изменён в другой вкладке. Обновите страницу.');
            }
            $data = $this->content->validate($kind, $request->all(), $old?->status === 'published');
            if ($kind === 'courses' && $old && DB::table('university_attempts')->where('course_slug', $id)->exists()) {
                abort_unless(json_decode($old->curriculum, true) === $data['curriculum'], 409, 'По курсу уже есть оценки. Создайте новую редакцию.');
            }
            $data = $this->content->encode($data);
            // Millisecond timestamp not available on legacy tables; force a distinct revision timestamp.
            $data['updated_at'] = $old && $old->updated_at >= now()->toDateTimeString()
                ? Carbon::parse($old->updated_at)->addSecond() : now();
            if ($old) {
                DB::table($table)->where('slug', $id)->update($data);
            } else {
                DB::table($table)->insert($data + ['slug' => $id, 'status' => 'draft', 'created_at' => now()]);
            }

            return $this->content->present(DB::table($table)->where('slug', $id)->first());
        });

        return response()->json(['item' => $item], $slug ? 200 : 201);
    }

    public function action(Request $request, string $kind, string $slug, string $action)
    {
        abort_unless(in_array($action, ['publish', 'archive', 'revision'], true), 404);
        $table = $this->content->table($kind);

        return DB::transaction(function () use ($request, $kind, $slug, $action, $table) {
            // Serialize editorial lifecycle operations. Readers/assessors lock individual course rows.
            $rows = DB::table($table)->orderBy('slug')->lockForUpdate()->get();
            $row = $rows->firstWhere('slug', $slug);
            abort_unless($row, 404);
            $request->validate(['updated_at' => 'required|string']);
            abort_unless($request->input('updated_at') === $row->updated_at, 409, 'Материал изменился. Обновите страницу.');
            if ($action === 'revision') {
                abort_unless($kind === 'courses', 404);
                $input = $request->validate(['slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique($table, 'slug')]]);
                $copy = (array) $row;
                $copy['slug'] = $input['slug'];
                $copy['previous_slug'] = $slug;
                $copy['status'] = 'draft';
                $copy['created_at'] = $copy['updated_at'] = now();
                DB::table($table)->insert($copy);

                return response()->json(['item' => $this->content->present(DB::table($table)->where('slug', $input['slug'])->first())], 201);
            }
            abort_if($action === 'archive' && $row->status === 'draft', 422, 'Черновик ещё не опубликован.');
            if ($action === 'publish') {
                $this->content->validate($kind, $this->content->present($row), true);
                if ($kind === 'courses') {
                    abort_if($rows->contains(fn ($r) => $r->previous_slug === $slug && $r->status !== 'draft'), 409, 'Этот курс уже заменён новой редакцией.');
                    if ($row->previous_slug && $row->status !== 'published') {
                        abort_unless($rows->firstWhere('slug', $row->previous_slug)?->status === 'published', 409, 'Создайте редакцию актуального опубликованного курса.');
                    }
                }
                if ($kind === 'courses' && $row->previous_slug) {
                    abort_if($rows->contains(fn ($r) => $r->previous_slug === $row->previous_slug && $r->slug !== $slug && $r->status === 'published'), 409, 'У исходного курса уже опубликована другая редакция.');
                    DB::table($table)->where('slug', $row->previous_slug)->update(['status' => 'archived', 'updated_at' => now()]);
                }
            }
            $stamp = $row->updated_at >= now()->toDateTimeString() ? Carbon::parse($row->updated_at)->addSecond() : now();
            DB::table($table)->where('slug', $slug)->update(['status' => $action === 'publish' ? 'published' : 'archived', 'updated_at' => $stamp]);

            return response()->json(['item' => $this->content->present(DB::table($table)->where('slug', $slug)->first())]);
        });
    }
}
