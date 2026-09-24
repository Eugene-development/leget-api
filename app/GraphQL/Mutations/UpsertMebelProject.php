<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\MebelProject;
use App\Models\Tag;
use App\Services\BrandTags;
use App\Support\MebelProjectImages;
use App\Support\RussianSlug;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpsertMebelProject
{
    /**
     * @param  mixed  $root
     * @param  array{input: array}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): MebelProject
    {
        $input = $args['input'];
        if (isset($input['value']) && is_string($input['value'])) {
            $input['value'] = trim($input['value']);
        }
        Validator::make($input, [
            'id' => ['sometimes', 'nullable', 'string'],
            'value' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'string', Rule::exists('categories', 'id')->where(fn ($query) => $query
                ->whereNull('deleted_at')->where('is_active', true)
                ->whereIn('rubric_id', DB::table('rubrics')->select('id')->where('slug', 'mebel')->whereNull('deleted_at')))],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'old_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
            'is_new' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'completed_at' => ['sometimes', 'nullable', 'date'],
            'object_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'maker' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hardware_brands' => ['sometimes', 'nullable', 'array', 'max:100'],
            'hardware_brands.*' => ['string', 'max:120'],
            'appliance_brands' => ['sometimes', 'nullable', 'array', 'max:100'],
            'appliance_brands.*' => ['string', 'max:120'],
            'tag_ids' => ['sometimes', 'array', 'max:200'],
            'tag_ids.*' => ['required', 'string', 'distinct', 'exists:tags,id'],
            'image_urls' => ['sometimes', 'nullable', 'array', 'max:8'],
            'image_urls.*' => ['required', 'string', 'distinct', 'max:2048'],
        ], [
            'value.required' => 'Введите номер проекта.',
            'category_id.exists' => 'Выберите действующую категорию мебели.',
            'price.min' => 'Цена не может быть отрицательной.',
            'old_price.min' => 'Старая цена не может быть отрицательной.',
            'image_urls.max' => 'Можно добавить не больше восьми фотографий.',
        ])->validate();

        $user = $context->user();
        $request = $context->request();
        $domain = $request->header('X-Forwarded-Host') ?? $request->getHost();

        $licenses = $user->licenses()->get();

        if ($licenses->isEmpty()) {
            throw new GraphQLException('No license found for the authenticated user.', 'VALIDATION');
        }

        // Try to find the license matching the current request domain
        $license = $licenses->where('domain', $domain)->first();

        // Fallback to the first license if no exact domain match found
        if (! $license) {
            $license = $licenses->first();
        }

        $licenseIds = $licenses->pluck('id')->toArray();

        if (isset($input['id'])) {
            // Разрешаем редактировать: глобальные проекты (license_id = null)
            // и проекты любой из лицензий пользователя
            $project = MebelProject::where('id', $input['id'])
                ->where(function ($q) use ($licenseIds) {
                    $q->whereNull('license_id')->orWhereIn('license_id', $licenseIds);
                })
                ->first();

            if (! $project) {
                throw new GraphQLException('Mebel project not found or you do not have permission to edit it.', 'VALIDATION');
            }

            // Глобальные проекты (license_id = null) остаются глобальными.
            // Не меняем license_id — иначе проект потеряется для других доменов.
        } else {
            $project = new MebelProject;
            $project->license_id = $license->id;

            // Generate a slug if creating
            $baseSlug = substr(RussianSlug::make($input['value']), 0, 220) ?: 'project';
            $project->slug = $baseSlug;

            // Ensure slug uniqueness
            $count = 1;
            while (MebelProject::withTrashed()->where('slug', $project->slug)->exists()) {
                $project->slug = "{$baseSlug}-{$count}";
                $count++;
            }
            $project->key = (string) Str::ulid();

            // Set default sort order for new projects
            $project->sort_order = MebelProject::where('category_id', $input['category_id'])
                ->whereIn('license_id', $licenseIds)
                ->max('sort_order') + 1;
        }

        if ($this->numberExists($project, $input['value'])) {
            throw ValidationException::withMessages(['value' => 'Проект с таким номером уже существует.']);
        }

        if (array_key_exists('tag_ids', $input)) {
            $tagLicense = $project->license_id ? $licenses->firstWhere('id', $project->license_id) : $license;
            $tags = Tag::whereIn('id', $input['tag_ids'])->get();
            $allowedGroups = DB::table('tag_group_rubric')->where('rubric_slug', 'mebel')->pluck('tag_group_id');
            $presented = app(BrandTags::class)->present($tags, $tagLicense)->keyBy('id');
            // Existing unavailable tags may be retained while editing; new broken/foreign links cannot be assigned.
            $previous = $project->exists ? $project->tags()->pluck('tags.id')->all() : [];
            foreach ($tags as $tag) {
                $item = $presented->get($tag->id);
                if ((! $project->license_id && $tag->license_id) || ! $allowedGroups->contains($tag->tag_group_id) || ! $item
                    || ($item['managed'] && ! $item['href'] && ! in_array($tag->id, $previous, true))) {
                    throw ValidationException::withMessages(['tag_ids' => 'Выберите доступные теги рубрики «Мебель» этого сайта.']);
                }
            }
        }

        $project->category_id = $input['category_id'];
        $project->value = $input['value'];

        if (array_key_exists('description', $input)) {
            $project->description = $input['description'];
        }
        if (array_key_exists('short_description', $input)) {
            $project->short_description = $input['short_description'];
        }
        // ── Паспорт сданной работы ───────────────────────────────────────
        // Единственная точка записи этих полей на всю платформу: страница
        // `/projects` их показывает и не правит (см. ProjectsFeed), а карточка
        // проекта в каталоге — правит. Второго редактора у них быть не должно.
        // Явный null — осознанное «работа ещё не сдана»: он стирает дату и
        // убирает проект из ленты, оставляя его в рубрике каталога. Отсутствие
        // ключа означает «поле не трогали», и это ровно та разница, ради которой
        // здесь `array_key_exists`, а не `isset`. Пустой строкой очистить нельзя:
        // скаляр `Date` разбирает вход в Carbon и на непарсящемся значении
        // отвечает ошибкой ещё до резолвера.
        if (array_key_exists('completed_at', $input)) {
            $project->completed_at = $input['completed_at'];
        }
        if (array_key_exists('object_address', $input)) {
            $project->object_address = $this->nullIfBlank($input['object_address']);
        }

        // Изготовитель и бренды живут в `meta`: их только показывают, ни
        // сортировки, ни выборки по ним нет. Ключи переписываются поштучно,
        // а не заменой всего `meta`, — там лежат и чужие произвольные атрибуты.
        $meta = $project->meta ?? [];

        if (array_key_exists('maker', $input)) {
            $meta['maker'] = $this->nullIfBlank($input['maker']);
        }
        if (array_key_exists('hardware_brands', $input)) {
            $meta['hardware_brands'] = $this->cleanBrands($input['hardware_brands']);
        }
        if (array_key_exists('appliance_brands', $input)) {
            $meta['appliance_brands'] = $this->cleanBrands($input['appliance_brands']);
        }

        // Пустые ключи выкидываем: `meta` отдаётся наружу целиком, и
        // `"maker": null` рядом с реальными атрибутами читается как «поле есть,
        // значение потеряли», тогда как отсутствие ключа означает то, что есть.
        $meta = array_filter(
            $meta,
            static fn ($value) => $value !== null && $value !== [] && $value !== '',
        );
        $project->meta = $meta === [] ? null : $meta;

        if (array_key_exists('price', $input)) {
            $project->price = $input['price'];
        }
        if (array_key_exists('old_price', $input)) {
            $project->old_price = $input['old_price'];
        }
        if (array_key_exists('is_featured', $input)) {
            $project->is_featured = $input['is_featured'];
        }
        if (array_key_exists('is_new', $input)) {
            $project->is_new = $input['is_new'];
        }
        if (array_key_exists('is_active', $input)) {
            $project->is_active = $input['is_active'];
        }

        // Existing images belong to this project already. Only new URLs need the
        // owner's upload-folder check; this also keeps legacy/global images usable.
        $existingImages = isset($input['image_urls']) && $project->exists
            ? $project->images()->get()->keyBy('path')
            : collect();
        $newImages = isset($input['image_urls'])
            ? collect(app(MebelProjectImages::class)->prepare(
                array_values(array_filter($input['image_urls'], fn ($url) => ! $existingImages->has($url))),
                $licenseIds,
            ))->keyBy('path')
            : null;
        try {
            DB::transaction(function () use ($project, $input, $existingImages, $newImages) {
                $project->save();
                if (array_key_exists('tag_ids', $input)) {
                    $project->tags()->sync($input['tag_ids']);
                }
                if ($newImages !== null) {
                    foreach ($existingImages as $url => $image) {
                        if (! in_array($url, $input['image_urls'], true)) {
                            $image->delete();
                        }
                    }
                    foreach ($input['image_urls'] as $index => $url) {
                        if ($existingImages->has($url)) {
                            $existingImages->get($url)->update(['sort_order' => $index + 1]);
                        } else {
                            $project->images()->create([
                                'key' => (string) Str::ulid(),
                                ...$newImages->get($url),
                                'sort_order' => $index + 1,
                            ]);
                        }
                    }
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            // Индекс закрывает гонку между проверкой и одновременной записью.
            if ($this->numberExists($project, $input['value'])) {
                throw ValidationException::withMessages(['value' => 'Проект с таким номером уже существует.']);
            }

            throw $e;
        }

        // Clear cache for this site to reflect changes immediately
        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException $e) {
            // Tagged cache not supported (file/database driver) — flush all cache
            Cache::flush();
        }

        return $project;
    }

    private function numberExists(MebelProject $project, string $number): bool
    {
        $query = MebelProject::withTrashed()->where('value', $number);

        if ($project->license_id !== null) {
            // Глобальные проекты также видны на сайте владельца.
            $query->where(function ($query) use ($project) {
                $query->whereNull('license_id')->orWhere('license_id', $project->license_id);
            });
        }

        if ($project->exists) {
            $query->where('id', '!=', $project->id);
        }

        return $query->exists();
    }

    /** Пустая и пробельная строка — это отсутствие значения, а не значение. */
    private function nullIfBlank(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Список брендов: обрезка, отсев пустых и дублей, переиндексация.
     *
     * Переиндексация обязательна: `array_values` после `array_filter` — разница
     * между JSON-массивом `["Blum"]` и JSON-объектом `{"1":"Blum"}`, а фронт
     * ждёт массив и на объекте молча покажет пустой список.
     *
     * @param  array<int, string>|null  $value
     * @return array<int, string>
     */
    private function cleanBrands(?array $value): array
    {
        if ($value === null) {
            return [];
        }

        $clean = [];
        foreach ($value as $brand) {
            $brand = trim((string) $brand);
            if ($brand !== '' && ! in_array($brand, $clean, true)) {
                $clean[] = $brand;
            }
        }

        return $clean;
    }
}
