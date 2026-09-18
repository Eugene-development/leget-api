<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\ApplianceBrand;
use App\Models\Category;
use App\Models\License;
use App\Services\ApplianceBrands;
use App\Support\BrandLogo;
use App\Support\RussianSlug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpsertApplianceBrand
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ApplianceBrand
    {
        $directory = app(ApplianceBrands::class);
        $rubric = $args['rubric'] ?? 'bytovaya-tehnika';
        $definition = $directory->definition($rubric);
        $license = $directory->authorize($args['licenseId'], $context->user());
        $input = $args['input'];
        $input['value'] = Str::squish($input['value'] ?? '');
        Validator::make($input, [
            'id' => ['sometimes', 'ulid'],
            'value' => ['required', 'string', 'max:120'],
            'logo' => ['required', 'string', 'max:2048'],
            'description' => ['required', 'string', 'max:20000'],
            'tag_ids' => ['present', 'array', 'max:200'],
            'tag_ids.*' => ['required', 'ulid', 'distinct', Rule::exists('tags', 'id')->where(fn ($q) => $q->whereIn('tag_group_id', DB::table('tag_groups')->select('id')->where('slug', $definition['tags'])))],
        ], ['value.required' => 'Введите название бренда.', 'description.required' => 'Добавьте описание бренда.', 'logo.required' => 'Загрузите логотип.'])->validate();
        if (trim(html_entity_decode(strip_tags($input['description']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xc2\xa0") === '') {
            throw ValidationException::withMessages(['description' => 'Добавьте описание бренда.']);
        }
        $existing = isset($input['id']) ? $directory->entries($license, $rubric)->firstWhere('id', $input['id']) : null;
        if (! $existing || $existing->logo !== $input['logo']) {
            app(BrandLogo::class)->validate($input['logo'], $license->id);
        }

        return DB::transaction(function () use ($directory, $license, $input, $context, $rubric) {
            $locked = License::whereKey($license->id)->lockForUpdate()->firstOrFail();
            $directory->authorize($locked->id, $context->user());
            $brand = isset($input['id']) ? $directory->editable($locked, $input['id'], $rubric) : new ApplianceBrand(['license_id' => $locked->id, 'rubric_slug' => $rubric]);
            $entries = $directory->entries($locked, $rubric);
            if ($entries->contains(fn ($entry) => Str::lower($entry->value) === Str::lower($input['value'])
                && (string) $entry->id !== (string) ($brand->source_category_id ?? $brand->id))) {
                throw ValidationException::withMessages(['value' => 'Бренд с таким названием уже есть на сайте.']);
            }
            if (! $brand->slug) {
                $base = RussianSlug::make($input['value']) ?: 'brand';
                $slug = $base;
                $index = 1;
                while (Category::where('slug', $slug)->exists() || ApplianceBrand::withTrashed()->where('license_id', $locked->id)->where('rubric_slug', $rubric)->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$index++;
                }
                $brand->slug = $slug;
                $brand->sort_order = ($entries->max('sort_order') ?? 0) + 10;
            }
            $brand->fill(array_intersect_key($input, array_flip(['value', 'logo', 'description'])));
            $brand->save();
            $brand->tags()->sync($input['tag_ids']);
            $directory->changed($locked);

            return $brand->load('tags');
        });
    }
}
