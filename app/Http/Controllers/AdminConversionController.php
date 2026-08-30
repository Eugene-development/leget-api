<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Conversion;
use App\Support\YandexConversionExport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminConversionController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $conversions = Conversion::query()
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'conversions' => $conversions,
            'summary' => [
                'total' => Conversion::query()->count(),
                'online' => Conversion::query()->where('channel', Conversion::CHANNEL_ONLINE)->count(),
                'offline' => Conversion::query()->where('channel', Conversion::CHANNEL_OFFLINE)->count(),
            ],
        ]);
    }

    public function storeOffline(Request $request, YandexConversionExport $export)
    {
        $validated = $request->validate([
            'offline_type' => 'required|string|in:call,email',
            'name' => 'required|string|max:255',
            'contact' => 'required|string|max:255',
            'ad_id' => ['nullable', 'string', 'regex:/^\d{1,32}$/'],
            'comment' => 'nullable|string|max:2000',
        ]);

        $contact = $validated['offline_type'] === 'call'
            ? $export->normalizePhone($validated['contact'])
            : $export->normalizeEmail($validated['contact']);

        if ($contact === null) {
            return response()->json([
                'success' => false,
                'message' => $validated['offline_type'] === 'call'
                    ? 'Укажите номер телефона с кодом страны.'
                    : 'Укажите корректный адрес электронной почты.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $conversion = Conversion::query()->create([
            'channel' => Conversion::CHANNEL_OFFLINE,
            'type' => 'offline_'.$validated['offline_type'],
            'name' => $validated['name'],
            'contact' => $contact,
            'ad_id' => $validated['ad_id'] ?? null,
            'comment' => $validated['comment'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Офлайн-конверсия добавлена.',
            'conversion' => $conversion,
        ], Response::HTTP_CREATED);
    }

    public function exportOffline(Request $request, YandexConversionExport $export): StreamedResponse
    {
        $validated = $request->validate([
            'period' => 'required|string|in:'.implode(',', YandexConversionExport::PERIODS),
            'date' => 'required|date_format:Y-m-d|before_or_equal:today',
        ]);

        [$start, $end] = $export->periodRange($validated['period'], $validated['date']);
        $query = Conversion::query()
            ->where('channel', Conversion::CHANNEL_OFFLINE)
            ->whereIn('type', YandexConversionExport::EXPORTABLE_TYPES)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($start->lessThanOrEqualTo($end)) {
            $query->whereBetween('created_at', [$start, $end]);
        } else {
            $query->whereRaw('1 = 0');
        }

        $filename = sprintf(
            'yandex-offline-conversions-%s-%s.csv',
            $validated['period'],
            $validated['date'],
        );

        return response()->streamDownload(
            static function () use ($export, $query): void {
                foreach ($export->lines($query->cursor()) as $line) {
                    echo $line;
                }
            },
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
