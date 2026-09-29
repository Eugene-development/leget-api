<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PromoCodeStatus;
use App\Enums\PromoDiscountType;
use App\Enums\PromoSubjectType;
use App\Enums\Role;
use App\Models\PromoCode;
use App\Models\PromoCodeEvent;
use App\Models\User;
use App\Services\CuratorCommissionService;
use App\Services\PromoCodeService;
use App\Support\PromoCodePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Кабинет куратора.
 *
 * Куратор доводит промокод до `deal_reported` и останавливается. Маршрутов
 * «подтвердить» и «закрыть» здесь нет вовсе — не потому что кнопку спрятали,
 * а потому что способность `promo.confirm` роли не выдана, и добавить такой
 * маршрут в эту группу нечем: группа закрыта `can:promo.curate`.
 *
 * Выборка сужена до `curator_id` вошедшего: куратор работает только со своими
 * клиентами и промокодами.
 *
 * В рекламную атрибуцию куратор не пишет: маршрута в `ad_attributions` из этой
 * группы не существует, а `attribution_id` промокода ставит сервер по клиенту.
 */
final class PromoCuratorController extends Controller
{
    public function __construct(
        private readonly PromoCodeService $service,
        private readonly PromoCodePresenter $presenter,
        private readonly CuratorCommissionService $commissions,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'status' => 'sometimes|nullable|string',
            'overdue' => 'sometimes|boolean',
        ]);

        $codes = $this->scope($request)
            ->when(
                isset($validated['status']) && $validated['status'] !== null,
                fn (Builder $q) => $q->where('status', $validated['status']),
            )
            ->when(
                (bool) ($validated['overdue'] ?? false),
                // Просроченное действие: срок вышел, а код ещё не в терминальном
                // состоянии — значит с ним ничего не сделали вовремя.
                fn (Builder $q) => $q->whereNotNull('expires_at')
                    ->where('expires_at', '<', now())
                    ->whereNotIn('status', [
                        PromoCodeStatus::Closed->value,
                        PromoCodeStatus::Cancelled->value,
                        PromoCodeStatus::Refunded->value,
                        PromoCodeStatus::Expired->value,
                    ]),
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->through(fn (PromoCode $promo): array => $this->presenter->forCurator($promo));

        return response()->json([
            'success' => true,
            'promo_codes' => $codes,
            'summary' => $this->summary($request),
        ]);
    }

    /**
     * Очередь новых клиентов — те, у кого ещё нет ни одного промокода.
     *
     * Назначения клиента куратору в схеме нет, и выдумывать его здесь нельзя:
     * очередь показывает всех клиентов без промокода, а «свои» появляются
     * у куратора в момент создания кода.
     */
    public function queue(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $clients = User::query()
            ->whereIn('role', [Role::Client->value, Role::Student->value])
            ->whereDoesntHave('promoCodes')
            ->with('attribution')
            ->latest('created_at')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->through(static fn (User $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'phone' => $client->phone,
                'region' => $client->region,
                'registered_at' => $client->created_at?->toIso8601String(),
                // Признак «пришёл из рекламы», без самих идентификаторов:
                // рекламная аналитика остаётся административной.
                'from_advertising' => $client->attribution !== null,
            ]);

        return response()->json(['success' => true, 'clients' => $clients]);
    }

    /**
     * Справочник партнёров для назначения.
     *
     * Только имя, компания и телефон: куратору нужно выбрать организацию,
     * а не получить выгрузку пользователей. Реквизиты берутся из
     * `partner_profiles` — второй таблицы организаций у платформы нет.
     */
    public function partners(Request $request)
    {
        $partners = User::query()
            ->where('role', Role::Partner->value)
            ->with('partnerProfile')
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(static fn (User $partner): array => [
                'id' => $partner->id,
                'name' => $partner->name,
                'company' => $partner->partnerProfile?->company,
                'partner_type' => $partner->partnerProfile?->partner_type?->label(),
                'city' => $partner->partnerProfile?->city,
                'phone' => $partner->phone,
            ])
            ->all();

        return response()->json(['success' => true, 'partners' => $partners]);
    }

    public function show(Request $request, string $id)
    {
        $promo = $this->find($request, $id);

        if ($promo === null) {
            return $this->missing();
        }

        return response()->json([
            'success' => true,
            'promo_code' => $this->presenter->forCurator($promo),
            'history' => $this->history($promo),
        ]);
    }

    /** Создать промокод клиенту. Куратором становится сам создающий. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|exists:users,id',
            'partner_id' => 'sometimes|nullable|integer|exists:users,id',
            'subject_type' => ['required', 'string', Rule::enum(PromoSubjectType::class)],
            'subject_id' => 'sometimes|nullable|string|max:26',
            'subject_title' => 'required|string|max:255',
            'discount_type' => ['required', 'string', Rule::enum(PromoDiscountType::class)],
            'discount_value' => 'required|numeric|min:0',
            'currency' => 'sometimes|nullable|string|size:3',
            'minimum_order_amount' => 'sometimes|nullable|numeric|min:0',
            'terms' => 'sometimes|nullable|string|max:2000',
            'starts_at' => 'sometimes|nullable|date',
            'expires_at' => 'sometimes|nullable|date',
        ]);

        $client = User::query()->find($validated['client_id']);

        if (! $client instanceof User || ! $client->hasAbility('promo.client')) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод выдаётся пользователю с ролью «Клиент».',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $partner = null;

        if (($validated['partner_id'] ?? null) !== null) {
            $partner = User::query()->find($validated['partner_id']);

            if (! $partner instanceof User || ($partner->role ?? Role::Client) !== Role::Partner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Назначить можно только пользователя с ролью «Партнёр».',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $promo = $this->service->create($request->user(), $client, $validated + [
            // Куратор — тот, кто создаёт. Прислать чужой `curator_id` в теле
            // нельзя: поле не читается, и приписать сделку другому не выйдет.
            'curator' => $request->user(),
            'partner' => $partner,
        ]);

        return response()->json([
            'success' => true,
            'promo_code' => $this->presenter->forCurator($promo->fresh(['client', 'partner.partnerProfile', 'deal'])),
        ], Response::HTTP_CREATED);
    }

    public function activate(Request $request, string $id)
    {
        $promo = $this->find($request, $id);

        if ($promo === null) {
            return $this->missing();
        }

        $this->service->activate($request->user(), $promo);

        return $this->card($request, $id);
    }

    public function assignPartner(Request $request, string $id)
    {
        $validated = $request->validate([
            'partner_id' => 'required|integer|exists:users,id',
        ]);

        $promo = $this->find($request, $id);

        if ($promo === null) {
            return $this->missing();
        }

        $partner = User::query()->findOrFail($validated['partner_id']);

        $this->service->assignPartner($request->user(), $promo, $partner);

        return $this->card($request, $id);
    }

    public function updateTerms(Request $request, string $id)
    {
        $validated = $request->validate([
            'discount_value' => 'sometimes|nullable|numeric|min:0',
            'terms' => 'sometimes|nullable|string|max:2000',
            'expires_at' => 'sometimes|nullable|date',
        ]);

        $promo = $this->find($request, $id);

        if ($promo === null) {
            return $this->missing();
        }

        $this->service->updateTerms($request->user(), $promo, $validated);

        return $this->card($request, $id);
    }

    /**
     * Внести сведения о сделке от имени партнёра.
     *
     * Оба поля обязательны: отметка «действую за партнёра» и основание.
     * Сервис откажет и без них, но правило продублировано в валидации, чтобы
     * человек получил внятную ошибку поля, а не доменную.
     *
     * Сделка становится ЗАЯВЛЕННОЙ, не подтверждённой.
     */
    public function reportDeal(Request $request, string $id)
    {
        $validated = $request->validate([
            'order_number' => 'required|string|max:64',
            'deal_date' => 'required|date|before_or_equal:today',
            'gross_amount' => 'required|numeric|min:0',
            'discount_amount' => 'required|numeric|min:0',
            'currency' => 'sometimes|nullable|string|size:3',
            'category' => 'sometimes|nullable|string|max:120',
            'comment' => 'sometimes|nullable|string|max:2000',
            'document_url' => 'sometimes|nullable|url|max:500',
            'on_behalf_of_partner' => 'accepted',
            'behalf_reason' => 'required|string|min:3|max:2000',
        ]);

        $promo = $this->find($request, $id);

        if ($promo === null) {
            return $this->missing();
        }

        $deal = $this->service->reportDeal($request->user(), $promo, $validated + [
            'on_behalf_of_partner' => true,
        ]);

        return response()->json([
            'success' => true,
            'promo_code' => $this->presenter->forCurator($promo->fresh(['client', 'partner.partnerProfile', 'deal'])),
            'deal' => $this->presenter->deal($deal),
        ], Response::HTTP_CREATED);
    }

    public function cancel(Request $request, string $id)
    {
        $validated = $request->validate(['reason' => 'required|string|min:3|max:2000']);

        $promo = $this->find($request, $id);

        if ($promo === null) {
            return $this->missing();
        }

        $this->service->cancel($request->user(), $promo, $validated['reason']);

        return $this->card($request, $id);
    }

    /**
     * Показатели куратора за период.
     *
     * Считаются по закрытым сделкам. Если формула вознаграждения не настроена,
     * `commission_total` возвращается NULL — «не настроено» и «ноль» разные
     * ответы, и подменять первый вторым нельзя.
     */
    public function report(Request $request)
    {
        $validated = $request->validate([
            'from' => 'sometimes|nullable|date_format:Y-m-d',
            'to' => 'sometimes|nullable|date_format:Y-m-d',
        ]);

        return response()->json([
            'success' => true,
            'report' => $this->commissions->report(
                $request->user(),
                $validated['from'] ?? null,
                $validated['to'] ?? null,
            ),
        ]);
    }

    /**
     * @return Builder<PromoCode>
     */
    private function scope(Request $request): Builder
    {
        return PromoCode::query()
            ->with(['client', 'partner.partnerProfile', 'deal'])
            ->visibleToCurator((int) $request->user()->id);
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
            'promo_code' => $promo === null ? null : $this->presenter->forCurator($promo),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function summary(Request $request): array
    {
        $counts = PromoCode::query()
            ->visibleToCurator((int) $request->user()->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $summary = [];

        foreach (PromoCodeStatus::values() as $status) {
            $summary[$status] = (int) ($counts[$status] ?? 0);
        }

        return $summary;
    }

    /**
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
