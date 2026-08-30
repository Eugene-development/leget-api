<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PromoAction;
use App\Enums\PromoCodeStatus;
use App\Enums\Role;
use App\Models\AdAttribution;
use App\Models\PromoCode;
use App\Models\PromoCodeEvent;
use App\Models\User;
use App\Services\CuratorCommissionService;
use App\Services\PromoAuditLogger;
use App\Services\PromoCodeService;
use App\Support\PromoCodePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Административный реестр промокодов и сделок.
 *
 * Единственная выдача, в которой присутствуют yclid и UTM: сквозная аналитика —
 * внутренние данные платформы, и ни клиент, ни партнёр, ни куратор их не видят.
 * Поэтому просмотр карточки здесь пишется в журнал как `promo.sensitive_viewed`:
 * раскрытие рекламной аналитики — действие, а не чтение.
 *
 * Подтверждение сделки без ответа клиента (`confirm`) закрыто отдельной
 * способностью `promo.confirm` — той самой, которой нет у куратора. Основание
 * обязательно, и оно попадает в журнал.
 */
final class AdminPromoController extends Controller
{
    public function __construct(
        private readonly PromoCodeService $service,
        private readonly PromoCodePresenter $presenter,
        private readonly CuratorCommissionService $commissions,
        private readonly PromoAuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'status' => 'sometimes|nullable|string',
            'client_id' => 'sometimes|nullable|integer',
            'curator_id' => 'sometimes|nullable|integer',
            'partner_id' => 'sometimes|nullable|integer',
            'utm_source' => 'sometimes|nullable|string|max:255',
            'from' => 'sometimes|nullable|date_format:Y-m-d',
            'to' => 'sometimes|nullable|date_format:Y-m-d',
        ]);

        $codes = $this->scope()
            ->when(($validated['status'] ?? null) !== null, fn (Builder $q) => $q->where('status', $validated['status']))
            ->when(($validated['client_id'] ?? null) !== null, fn (Builder $q) => $q->where('client_id', $validated['client_id']))
            ->when(($validated['curator_id'] ?? null) !== null, fn (Builder $q) => $q->where('curator_id', $validated['curator_id']))
            ->when(($validated['partner_id'] ?? null) !== null, fn (Builder $q) => $q->where('partner_id', $validated['partner_id']))
            ->when(
                ($validated['utm_source'] ?? null) !== null,
                // Фильтр по рекламному источнику — join к атрибуции, а не
                // подзапрос на каждую строку.
                fn (Builder $q) => $q->whereHas(
                    'attribution',
                    fn (Builder $a) => $a->where('first_utm_source', $validated['utm_source'])
                        ->orWhere('last_utm_source', $validated['utm_source']),
                ),
            )
            ->when(($validated['from'] ?? null) !== null, fn (Builder $q) => $q->whereDate('created_at', '>=', $validated['from']))
            ->when(($validated['to'] ?? null) !== null, fn (Builder $q) => $q->whereDate('created_at', '<=', $validated['to']))
            ->latest('created_at')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->through(fn (PromoCode $promo): array => $this->presenter->forAdmin($promo));

        return response()->json([
            'success' => true,
            'promo_codes' => $codes,
            'summary' => $this->summary(),
        ]);
    }

    public function show(Request $request, string $id)
    {
        $promo = $this->scope()->whereKey($id)->first();

        if (! $promo instanceof PromoCode) {
            return $this->missing();
        }

        // Карточка раскрывает рекламную аналитику — фиксируем просмотр.
        // В списке этого не делаем: запись на каждую страницу реестра
        // превратила бы журнал в лог доступа и утопила бы в нём разбор споров.
        $this->audit->log(
            action: PromoAction::SensitiveViewed,
            subjectType: 'promo_code',
            subjectId: (string) $promo->getKey(),
            actor: $request->user(),
            promoCodeId: (string) $promo->getKey(),
        );

        return response()->json([
            'success' => true,
            'promo_code' => $this->presenter->forAdmin($promo),
            'history' => PromoCodeEvent::query()
                ->where('promo_code_id', $promo->getKey())
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(200)
                ->get()
                ->map(fn (PromoCodeEvent $event): array => $this->presenter->event($event))
                ->all(),
        ]);
    }

    /**
     * Подтвердить сделку без ответа клиента.
     *
     * Отдельное право (`promo.confirm`), обязательное основание, запись
     * в журнале. Это единственный путь, на котором сделка становится
     * подтверждённой без второй стороны.
     */
    public function confirm(Request $request, string $id)
    {
        $validated = $request->validate(['reason' => 'required|string|min:3|max:2000']);

        $promo = $this->scope()->whereKey($id)->first();

        if (! $promo instanceof PromoCode) {
            return $this->missing();
        }

        $deal = $this->service->confirmAsAdmin($request->user(), $promo, $validated['reason']);

        return response()->json([
            'success' => true,
            'promo_code' => $this->presenter->forAdmin($promo->fresh($this->relations())),
            'deal' => $this->presenter->deal($deal),
        ]);
    }

    /**
     * Закрыть сделку — платформа принимает её в аналитику и расчёт.
     *
     * Отдельный шаг после подтверждения и отдельный актор: «сторона
     * подтвердила» и «платформа приняла» остаются двумя разными записями.
     * Куратор не может выполнить ни одну из них.
     */
    public function close(Request $request, string $id)
    {
        $promo = $this->scope()->whereKey($id)->first();

        if (! $promo instanceof PromoCode) {
            return $this->missing();
        }

        $deal = $this->service->close($request->user(), $promo);

        return response()->json([
            'success' => true,
            'promo_code' => $this->presenter->forAdmin($promo->fresh($this->relations())),
            'deal' => $this->presenter->deal($deal),
        ]);
    }

    public function cancel(Request $request, string $id)
    {
        $validated = $request->validate(['reason' => 'required|string|min:3|max:2000']);

        $promo = $this->scope()->whereKey($id)->first();

        if (! $promo instanceof PromoCode) {
            return $this->missing();
        }

        $this->service->cancel($request->user(), $promo, $validated['reason']);

        return response()->json([
            'success' => true,
            'promo_code' => $this->presenter->forAdmin($promo->fresh($this->relations())),
        ]);
    }

    /** Возврат: сделка исключается из вознаграждения, начисление сторнируется. */
    public function refund(Request $request, string $id)
    {
        $validated = $request->validate(['reason' => 'required|string|min:3|max:2000']);

        $promo = $this->scope()->whereKey($id)->first();

        if (! $promo instanceof PromoCode) {
            return $this->missing();
        }

        $this->service->refund($request->user(), $promo, $validated['reason']);

        return response()->json([
            'success' => true,
            'promo_code' => $this->presenter->forAdmin($promo->fresh($this->relations())),
        ]);
    }

    /** Отчёт по кураторам за период. */
    public function curatorsReport(Request $request)
    {
        $validated = $request->validate([
            'from' => 'sometimes|nullable|date_format:Y-m-d',
            'to' => 'sometimes|nullable|date_format:Y-m-d',
        ]);

        $curators = User::query()
            ->where('role', Role::Curator->value)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'rule' => $this->commissions->rule(),
            'curators' => $curators
                ->map(fn (User $curator): array => $this->commissions->report(
                    $curator,
                    $validated['from'] ?? null,
                    $validated['to'] ?? null,
                ))
                ->all(),
        ]);
    }

    /**
     * Отчёт по партнёрам: обороты закрытых сделок.
     *
     * Одним агрегирующим запросом, а не выборкой всех сделок в память:
     * реестр растёт, а отчёт должен оставаться константным по памяти.
     */
    public function partnersReport(Request $request)
    {
        $validated = $request->validate([
            'from' => 'sometimes|nullable|date_format:Y-m-d',
            'to' => 'sometimes|nullable|date_format:Y-m-d',
        ]);

        $rows = DB::table('promo_code_deals')
            ->join('promo_codes', 'promo_codes.id', '=', 'promo_code_deals.promo_code_id')
            ->leftJoin('users', 'users.id', '=', 'promo_codes.partner_id')
            ->where('promo_codes.status', PromoCodeStatus::Closed->value)
            ->when(($validated['from'] ?? null) !== null, fn ($q) => $q->whereDate('promo_code_deals.closed_at', '>=', $validated['from']))
            ->when(($validated['to'] ?? null) !== null, fn ($q) => $q->whereDate('promo_code_deals.closed_at', '<=', $validated['to']))
            ->groupBy('promo_codes.partner_id', 'users.name')
            ->select([
                'promo_codes.partner_id',
                'users.name as partner_name',
                DB::raw('COUNT(*) as deals'),
                DB::raw('SUM(promo_code_deals.gross_amount) as gross_total'),
                DB::raw('SUM(promo_code_deals.discount_amount) as discount_total'),
                DB::raw('SUM(promo_code_deals.net_amount) as net_total'),
            ])
            ->get();

        return response()->json(['success' => true, 'partners' => $rows]);
    }

    /**
     * Отчёт по рекламным источникам — связь подтверждённой сделки с рекламой.
     *
     * Здесь yclid и UTM показываются: это административная выдача, и ради неё
     * атрибуция и хранится.
     */
    public function sourcesReport(Request $request)
    {
        $validated = $request->validate([
            'from' => 'sometimes|nullable|date_format:Y-m-d',
            'to' => 'sometimes|nullable|date_format:Y-m-d',
            'per_page' => 'sometimes|integer|min:1|max:200',
        ]);

        $deals = PromoCode::query()
            // `partner.partnerProfile`, а не голый `partner`: презентер достаёт
            // из профиля название компании, и без вложенной связи каждая строка
            // отчёта тянула бы свой запрос.
            ->with($this->relations())
            ->where('status', PromoCodeStatus::Closed->value)
            ->when(($validated['from'] ?? null) !== null, fn (Builder $q) => $q->whereDate('closed_at', '>=', $validated['from']))
            ->when(($validated['to'] ?? null) !== null, fn (Builder $q) => $q->whereDate('closed_at', '<=', $validated['to']))
            ->latest('closed_at')
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->through(function (PromoCode $promo): array {
                $attribution = $promo->attribution;
                $deal = $promo->deal;
                $card = $this->presenter->forAdmin($promo);

                return [
                    'promo_code' => $promo->code,
                    'source' => $attribution?->first_utm_source,
                    'campaign' => $attribution?->first_utm_campaign,
                    'campaign_id' => $attribution?->first_campaign_id,
                    'ad_group_id' => $attribution?->first_ad_group_id,
                    'ad_id' => $attribution?->first_ad_id,
                    'keyword_id' => $attribution?->first_keyword_id,
                    'yclid' => $attribution?->first_yclid,
                    'client' => $promo->client?->name,
                    'curator' => $promo->curator?->name,
                    'partner' => $promo->partner?->name,
                    'reported_at' => $promo->deal_reported_at?->toIso8601String(),
                    'confirmed_at' => $promo->client_confirmed_at?->toIso8601String(),
                    'closed_at' => $promo->closed_at?->toIso8601String(),
                    'gross_amount' => $deal?->gross_amount,
                    'discount_amount' => $deal?->discount_amount,
                    'net_amount' => $deal?->net_amount,
                    'status' => $promo->status->value,
                    'timings' => $card['timings'],
                ];
            });

        return response()->json([
            'success' => true,
            'rows' => $deals,
            'attributed_clients' => AdAttribution::query()->count(),
        ]);
    }

    /**
     * @return Builder<PromoCode>
     */
    private function scope(): Builder
    {
        return PromoCode::query()->with($this->relations());
    }

    /**
     * @return list<string>
     */
    private function relations(): array
    {
        return ['client', 'curator', 'partner.partnerProfile', 'attribution', 'deal'];
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        $counts = PromoCode::query()
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

    private function missing()
    {
        return response()->json([
            'success' => false,
            'message' => 'Промокод не найден.',
        ], Response::HTTP_NOT_FOUND);
    }
}
