<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GraphQLException;
use App\Models\Category;
use App\Models\License;
use App\Models\MebelProject;
use App\Support\MebelProjectImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Promo-2 products use the existing furniture catalog, scoped to one site. */
final class Promo2Products
{
    private const CATEGORIES = ['kitchen' => 'kuhni', 'wardrobe' => 'shkafy'];

    private function categorySlug(string $kind): string
    {
        if (! isset(self::CATEGORIES[$kind])) {
            throw ValidationException::withMessages(['kind' => 'Выберите кухни или шкафы.']);
        }

        return self::CATEGORIES[$kind];
    }

    public function query(License $license, string $kind = 'kitchen'): Builder
    {
        $slug = $this->categorySlug($kind);

        return MebelProject::where('license_id', $license->id)
            ->whereHas('category', fn ($q) => $q->where('slug', $slug)
                ->whereHas('rubric', fn ($r) => $r->where('slug', 'mebel')))
            ->with(['images' => fn ($q) => $q->active()])
            ->orderBy('sort_order')->orderBy('id');
    }

    public function items(License $license, string $kind = 'kitchen', bool $includeHidden = false): array
    {
        $query = $this->query($license, $kind);
        if (! $includeHidden) {
            $query->active();
        }

        return $query->get()->map(fn ($product) => $this->present($product))->all();
    }

    public function present(MebelProject $product): array
    {
        return [
            'id' => $product->id,
            'title' => $product->meta['promo2_title'] ?? $product->value,
            'description' => $product->short_description ?? '',
            'image' => $product->images->first()?->url ?? '',
            'isActive' => $product->is_active,
            'sortOrder' => $product->sort_order,
        ];
    }

    public function save(License $license, array $input, string $kind = 'kitchen'): array
    {
        $categorySlug = $this->categorySlug($kind);
        $input['title'] = is_string($input['title'] ?? null) ? trim($input['title']) : ($input['title'] ?? null);
        Validator::make($input, [
            'id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['present', 'nullable', 'string', 'max:1000'],
            'image' => ['required', 'string', 'max:2048', 'url:https'],
            'isActive' => ['required', 'boolean'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:1000000'],
        ])->validate();

        $product = DB::transaction(function () use ($license, $input, $kind, $categorySlug) {
            License::whereKey($license->id)->lockForUpdate()->firstOrFail();
            $category = Category::where('slug', $categorySlug)->active()
                ->whereHas('rubric', fn ($q) => $q->where('slug', 'mebel')->where('is_active', true))->firstOrFail();
            if (! empty($input['id'])) {
                $product = $this->query($license, $kind)->whereKey($input['id'])->lockForUpdate()->first();
                if (! $product) {
                    throw new GraphQLException('Товар не найден в этом разделе или недоступен для редактирования.', 'AUTHORIZATION');
                }
            } else {
                $product = new MebelProject;
                $product->id = (string) Str::ulid();
                $product->key = (string) Str::ulid();
                $product->slug = $kind.'-'.strtolower($product->id);
                // Project numbers are unique per license; catalog display names may repeat.
                $product->value = 'P2-'.$product->id;
                // Explicit even in local mode: editing this gallery never changes a shared catalog.
                $product->license_id = $license->id;
            }
            $image = $product->exists ? $product->images()->active()->where('path', $input['image'])->first() : null;
            $prepared = $image ? null : app(MebelProjectImages::class)->prepare([$input['image']], [$license->id])[0];
            $product->fill([
                'category_id' => $category->id,
                'meta' => [...($product->meta ?? []), 'promo2_title' => $input['title']],
                'short_description' => $input['description'] ?? '',
                'is_active' => $input['isActive'],
                'sort_order' => $input['sortOrder'],
            ])->save();
            if ($prepared !== null) {
                $product->images()->get()->each->delete();
                $product->images()->create(['key' => (string) Str::ulid(), ...$prepared]);
            }

            return $product->load(['images' => fn ($q) => $q->active()]);
        });
        $this->invalidate($license);

        return $this->present($product);
    }

    public function delete(License $license, string $id, string $kind = 'kitchen'): string
    {
        Validator::make(['id' => $id], ['id' => ['required', 'string', 'ulid']])->validate();
        DB::transaction(function () use ($license, $id, $kind) {
            $product = $this->query($license, $kind)->withTrashed()->whereKey($id)->lockForUpdate()->first();
            if (! $product) {
                throw new GraphQLException('Товар не найден в этом разделе или недоступен для удаления.', 'AUTHORIZATION');
            }
            if (! $product->trashed()) {
                $product->delete();
            }
        });
        $this->invalidate($license);

        return $id;
    }

    private function invalidate(License $license): void
    {
        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException) {
            Cache::flush();
        }
    }
}
