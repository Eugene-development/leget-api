<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AttributionRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Перенос рекламной атрибуции из браузера в БД после регистрации клиента.
 *
 * Единственный маршрут, который пишет в `ad_attributions`, и пишет он только
 * про того, чей токен предъявлен: `user_id` берётся из `$request->user()`,
 * а не из тела. Поэтому подставить чужой `client_id` нечем — поля для него
 * в запросе просто нет.
 *
 * Куратора и партнёра сюда не пускает не проверка роли, а отсутствие маршрута:
 * второго входа в эту таблицу не существует. Исходная атрибуция — то, из чего
 * растёт вознаграждение куратора, и переписывать её он не должен.
 */
final class AttributionController extends Controller
{
    public function store(Request $request, AttributionRecorder $recorder)
    {
        $validated = $request->validate([
            'visitor_id' => 'sometimes|nullable|string|max:64',
            'first' => 'sometimes|nullable|array',
            'last' => 'sometimes|nullable|array',
            ...$this->touchRules('first'),
            ...$this->touchRules('last'),
        ]);

        $attribution = $recorder->record($request->user(), $validated);

        return response()->json([
            'success' => true,
            // Идентификатор нужен только для отладки: связь с промокодом
            // ставит сервер сам при создании кода.
            'attribution_id' => $attribution?->getKey(),
            'recorded' => $attribution !== null,
        ], $attribution === null ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /**
     * Правила для одного касания.
     *
     * URL'ы длиннее остальных полей: `landing_url` с длинным набором меток
     * легко перешагивает 255 символов, и обрезать его валидацией значило бы
     * потерять ровно те параметры, ради которых он сохраняется.
     *
     * @return array<string, string>
     */
    private function touchRules(string $prefix): array
    {
        $rules = [];

        foreach (['yclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'campaign_id', 'ad_group_id', 'ad_id', 'keyword_id'] as $field) {
            $rules["{$prefix}.{$field}"] = 'sometimes|nullable|string|max:255';
        }

        $rules["{$prefix}.landing_url"] = 'sometimes|nullable|string|max:2048';
        $rules["{$prefix}.referrer"] = 'sometimes|nullable|string|max:2048';
        $rules["{$prefix}.touched_at"] = 'sometimes|nullable|date';

        return $rules;
    }
}
