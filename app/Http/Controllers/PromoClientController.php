<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PromoClientResponse;
use App\Models\PromoCode;
use App\Services\PromoCodeService;
use App\Support\PromoCodePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Промокоды клиента.
 *
 * Каждая выборка сужена до `client_id` вошедшего — не фильтром «по умолчанию»,
 * а единственным способом достать строку: метода, который ищет промокод
 * по идентификатору без этого условия, здесь нет. Чужой код возвращает 404,
 * а не 403: существование чужого промокода — тоже сведение.
 *
 * `client_id` из тела запроса не читается нигде. Подменить его нечем.
 */
final class PromoClientController extends Controller
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
            'status' => ['sometimes', 'nullable', 'string'],
        ]);

        $codes = $this->scope($request)
            ->when(
                isset($validated['status']) && $validated['status'] !== null,
                fn (Builder $q) => $q->where('status', $validated['status']),
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->through(fn (PromoCode $promo): array => $this->presenter->forClient($promo));

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
            'promo_code' => $this->presenter->forClient($promo),
        ]);
    }

    /**
     * Ответ клиента на заявленную сделку: подтвердить или оспорить.
     *
     * Действие принадлежит только владельцу кода. Проверка не в том, что
     * страница спрятала кнопку, а в том, что выборка не отдаст чужую строку.
     */
    public function respond(Request $request, string $id)
    {
        $validated = $request->validate([
            'response' => ['required', 'string', Rule::enum(PromoClientResponse::class)],
            'comment' => 'sometimes|nullable|string|max:2000',
        ]);

        $promo = $this->find($request, $id);

        if ($promo === null) {
            return $this->missing();
        }

        $deal = $this->service->respondAsClient(
            $request->user(),
            $promo,
            PromoClientResponse::from($validated['response']),
            $validated['comment'] ?? null,
        );

        return response()->json([
            'success' => true,
            'promo_code' => $this->presenter->forClient($promo->fresh(['partner', 'curator', 'deal'])),
            'deal' => $this->presenter->deal($deal),
        ]);
    }

    /**
     * @return Builder<PromoCode>
     */
    private function scope(Request $request): Builder
    {
        return PromoCode::query()
            // Жадная загрузка: без неё список из 25 кодов дал бы 75 запросов
            // на партнёра, куратора и сделку.
            ->with(['partner.partnerProfile', 'curator', 'deal'])
            ->visibleToClient((int) $request->user()->id);
    }

    private function find(Request $request, string $id): ?PromoCode
    {
        return $this->scope($request)->whereKey($id)->first();
    }

    private function missing()
    {
        return response()->json([
            'success' => false,
            'message' => 'Промокод не найден.',
        ], Response::HTTP_NOT_FOUND);
    }
}
