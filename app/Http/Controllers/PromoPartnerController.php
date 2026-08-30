<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PromoCode;
use App\Models\PromoCodeEvent;
use App\Services\PromoCodeService;
use App\Support\PromoCodePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Промокоды, назначенные партнёру.
 *
 * Выборка сужена до `partner_id` вошедшего и исключает неактивированные коды:
 * до активации куратором код — черновик, и партнёру его существование знать
 * незачем. Чужой код возвращает 404.
 *
 * Ни `partner_id`, ни суммы, ни статус из тела запроса не читаются: партнёр
 * присылает сведения о сделке, а `net_amount` пересчитывает сервер.
 *
 * Партнёр НЕ может подтвердить собственную сделку: маршрута нет, а способность
 * `promo.confirm` роли не выдана.
 */
final class PromoPartnerController extends Controller
{
    public function __construct(
        private readonly PromoCodeService $service,
        private readonly PromoCodePresenter $presenter,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'status' => 'sometimes|nullable|string',
            'code' => 'sometimes|nullable|string|max:32',
        ]);

        $codes = $this->scope($request)
            ->when(
                isset($validated['status']) && $validated['status'] !== null,
                fn (Builder $q) => $q->where('status', $validated['status']),
            )
            ->when(
                isset($validated['code']) && $validated['code'] !== null,
                // Поиск по коду, а не по клиенту: партнёру код называют вслух.
                // Подстановочные знаки экранируем: `_` в запросе — это символ
                // подчёркивания, который человек ввёл, а не «любой символ».
                fn (Builder $q) => $q->where('code', 'like', '%'.$this->escapeLike($validated['code']).'%'),
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->through(fn (PromoCode $promo): array => $this->presenter->forPartner($promo));

        return response()->json(['success' => true, 'promo_codes' => $codes]);
    }

    public function show(Request $request, string $id)
    {
        $promo = $this->find($request, $id);

        if ($promo === null) {
            return $this->missing();
        }

        return response()->json([
            'success' => true,
            'promo_code' => $this->presenter->forPartner($promo),
            'history' => $this->history($promo),
        ]);
    }

    /** Отметить предъявление кода. */
    public function present(Request $request, string $id)
    {
        $promo = $this->find($request, $id);

        if ($promo === null) {
            return $this->missing();
        }

        $this->service->markPresented($request->user(), $promo);

        return $this->card($request, $id);
    }

    /** Отметить оформление заказа или договора. */
    public function order(Request $request, string $id)
    {
        $promo = $this->find($request, $id);

        if ($promo === null) {
            return $this->missing();
        }

        $this->service->markOrderCreated($request->user(), $promo);

        return $this->card($request, $id);
    }

    /**
     * Заявить сделку.
     *
     * Именно «заявить», а не «закрыть»: код уходит в `deal_reported`, и дальше
     * ходит клиент. Партнёр не может подтвердить сделку, из которой сам
     * получает выгоду.
     */
    public function reportDeal(Request $request, string $id)
    {
        $validated = $request->validate([
            'order_number' => 'required|string|max:64',
            'deal_date' => 'required|date|before_or_equal:today',
            // Суммы принимаем строками: JSON-число прошло бы через float,
            // а деньги в двоичной дроби теряют копейку.
            'gross_amount' => 'required|numeric|min:0',
            'discount_amount' => 'required|numeric|min:0',
            'currency' => 'sometimes|nullable|string|size:3',
            'category' => 'sometimes|nullable|string|max:120',
            'comment' => 'sometimes|nullable|string|max:2000',
            'document_url' => 'sometimes|nullable|url|max:500',
        ]);

        $promo = $this->find($request, $id);

        if ($promo === null) {
            return $this->missing();
        }

        $deal = $this->service->reportDeal($request->user(), $promo, $validated);

        return response()->json([
            'success' => true,
            'promo_code' => $this->presenter->forPartner($promo->fresh(['client', 'curator', 'deal'])),
            'deal' => $this->presenter->deal($deal),
        ], Response::HTTP_CREATED);
    }

    /** Экранирование `%`, `_` и `\\` для LIKE — они введены человеком как текст. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return Builder<PromoCode>
     */
    private function scope(Request $request): Builder
    {
        return PromoCode::query()
            ->with(['client', 'curator', 'deal'])
            ->visibleToPartner((int) $request->user()->id);
    }

    private function find(Request $request, string $id): ?PromoCode
    {
        return $this->scope($request)->whereKey($id)->first();
    }

    private function card(Request $request, string $id)
    {
        $promo = $this->find($request, $id);

        return response()->json([
            'success' => true,
            'promo_code' => $promo === null ? null : $this->presenter->forPartner($promo),
        ]);
    }

    /**
     * История действий без IP и user agent — журнал доступа партнёру не нужен.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(PromoCode $promo): array
    {
        return PromoCodeEvent::query()
            ->where('promo_code_id', $promo->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (PromoCodeEvent $event): array => $this->presenter->eventForParticipant($event))
            ->all();
    }

    private function missing()
    {
        return response()->json([
            'success' => false,
            'message' => 'Промокод не найден.',
        ], Response::HTTP_NOT_FOUND);
    }
}
