<?php

declare(strict_types=1);

namespace App\Services\Growth;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class EstimateCalculator
{
    public static function rules(array $input): array
    {
        $rules = Validator::make($input, [
            'base_min' => 'required|numeric|min:0|max:100000000',
            'base_max' => 'required|numeric|gte:base_min|max:100000000',
            'equipment_min' => 'required|numeric|min:0|max:10000000',
            'equipment_max' => 'required|numeric|gte:equipment_min|max:10000000',
            'layouts' => 'required|array:straight,l,u,island',
            'layouts.straight' => 'required|numeric|min:0.1|max:10',
            'layouts.l' => 'required|numeric|min:0.1|max:10',
            'layouts.u' => 'required|numeric|min:0.1|max:10',
            'layouts.island' => 'required|numeric|min:0.1|max:10',
            'materials' => 'required|array|min:1|max:12',
            'materials.*' => 'required|array:key,label,multiplier',
            'materials.*.key' => 'required|string|regex:/^[a-z0-9-]{1,40}$/|distinct',
            'materials.*.label' => 'required|string|min:1|max:80',
            'materials.*.multiplier' => 'required|numeric|min:0.1|max:10',
            'note' => 'nullable|string|max:1000',
        ])->validate();
        // Store a stable numeric representation, with no unexpected nested keys.
        foreach (['base_min', 'base_max', 'equipment_min', 'equipment_max'] as $key) {
            $rules[$key] = (float) $rules[$key];
        }
        $rules['layouts'] = array_map('floatval', $rules['layouts']);
        $rules['materials'] = array_values(array_map(fn ($material) => ['key' => $material['key'], 'label' => $material['label'], 'multiplier' => (float) $material['multiplier']], $rules['materials']));

        return $rules;
    }

    public static function calculate(array $rules, array $input): array
    {
        $data = Validator::make($input, [
            'run_cm' => 'required|integer|min:50|max:3000',
            'layout' => ['required', Rule::in(['straight', 'l', 'u', 'island'])],
            'material' => ['required', Rule::in(array_column($rules['materials'], 'key'))],
            'equipment' => 'required|integer|min:0|max:10',
        ])->validate();
        $material = collect($rules['materials'])->firstWhere('key', $data['material']);
        $factor = $data['run_cm'] / 100 * $rules['layouts'][$data['layout']] * $material['multiplier'];
        $min = (int) floor(($rules['base_min'] * $factor + $rules['equipment_min'] * $data['equipment']) / 100) * 100;
        $max = (int) ceil(($rules['base_max'] * $factor + $rules['equipment_max'] * $data['equipment']) / 100) * 100;

        return ['inputs' => $data, 'material_label' => $material['label'], 'min' => $min, 'max' => $max, 'currency' => 'RUB', 'note' => $rules['note'] ?? ''];
    }
}
