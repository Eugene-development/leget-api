<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CommissionEntryType;
use App\Enums\PromoAction;
use App\Enums\PromoCodeStatus;
use App\Models\CuratorCommission;
use App\Models\PromoCode;
use App\Models\PromoCodeDeal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Расчёт переменного вознаграждения куратора.
 *
 * Начисление происходит по закрытию сделки платформой, а НЕ по нажатию
 * куратором кнопки погашения. Между этими двумя моментами стоит подтверждение
 * клиента (или администратора с основанием) — иначе куратор, который вносит
 * сведения от имени партнёра, начислял бы себе сам.
 *
 * Денежных ставок в коде нет. Формулу платформа не согласовала, поэтому пока
 * `config('promo.commission')` пуст, сумма начисления остаётся NULL: сделка
 * учтена, отчёт считает количество и обороты, а колонка вознаграждения честно
 * пуста. Придумать ставку значило бы начислить людям деньги по выдуманному
 * правилу.
 *
 * Расчёты идут bcmath со скалой 2 — как в BillingService и PaymentService.
 * Float в денежных операциях не участвует нигде.
 */
final class CuratorCommissionService
{
    public const RULE_FIXED = 'fixed_per_closed_deal';

    public const RULE_PERCENT = 'percent_of_platform_commission';

    public const RULE_UNCONFIGURED = 'unconfigured';

    private const SCALE = 2;

    public function __construct(private readonly PromoAuditLogger $audit) {}

    /**
     * Начислить вознаграждение по закрытой сделке.
     *
     * Идемпотентно: уникальный индекс `(promo_code_deal_id, entry_type)` не даёт
     * появиться второму начислению, а перехват нарушения возвращает уже
     * существующую запись. Проверка «а есть ли уже» без индекса проиграла бы
     * гонке двух одновременных закрытий.
     */
    public function accrue(PromoCodeDeal $deal): ?CuratorCommission
    {
        $promo = $deal->promoCode;

        if (! $promo instanceof PromoCode || $promo->curator_id === null) {
            // Промокод без куратора — начислять некому. Это законная ситуация:
            // код мог создать администратор напрямую.
            return null;
        }

        if (! $promo->status->countsTowardsCommission()) {
            return null;
        }

        $rule = $this->rule();

        try {
            $commission = DB::transaction(function () use ($deal, $promo, $rule): CuratorCommission {
                $commission = new CuratorCommission;

                $commission->forceFill([
                    'promo_code_deal_id' => $deal->getKey(),
                    'promo_code_id' => $promo->getKey(),
                    'curator_id' => $promo->curator_id,
                    'entry_type' => CommissionEntryType::Accrual->value,
                    'rule' => $rule['rule'],
                    // Снимок правила: изменение настроек не должно переписать
                    // прошлые начисления задним числом.
                    'rule_snapshot' => $rule,
                    'amount' => $this->amount($rule, $deal),
                    'currency' => $deal->currency ?: (string) config('promo.commission.currency'),
                    'deal_gross_amount' => $deal->gross_amount,
                    'deal_discount_amount' => $deal->discount_amount,
                    'accrued_at' => now(),
                ])->save();

                $this->audit->log(
                    action: PromoAction::CommissionAccrued,
                    subjectType: 'curator_commission',
                    subjectId: (string) $commission->getKey(),
                    actor: null,
                    promoCodeId: (string) $promo->getKey(),
                    changes: [
                        'rule' => $rule['rule'],
                        'amount' => $commission->amount,
                        'currency' => $commission->currency,
                    ],
                );

                return $commission;
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            if (! $this->isDuplicate($e)) {
                throw $e;
            }

            return $this->entry($deal, CommissionEntryType::Accrual);
        }

        return $commission;
    }

    /**
     * Сторнировать начисление при возврате или отмене после закрытия.
     *
     * История не удаляется — появляется корректирующая запись с отрицательной
     * суммой. Иначе разбор «почему в прошлом месяце было больше» упирался бы
     * в отсутствующую строку.
     */
    public function reverse(PromoCodeDeal $deal, ?string $reason = null): ?CuratorCommission
    {
        $accrual = $this->entry($deal, CommissionEntryType::Accrual);

        if (! $accrual instanceof CuratorCommission) {
            // Начисления не было — сторнировать нечего. Не ошибка: возврат мог
            // прийти по сделке, которую платформа так и не закрыла.
            return null;
        }

        if ($this->entry($deal, CommissionEntryType::Reversal) instanceof CuratorCommission) {
            return $this->entry($deal, CommissionEntryType::Reversal);
        }

        try {
            return DB::transaction(function () use ($accrual, $deal, $reason): CuratorCommission {
                $reversal = new CuratorCommission;

                $reversal->forceFill([
                    'promo_code_deal_id' => $deal->getKey(),
                    'promo_code_id' => $accrual->promo_code_id,
                    'curator_id' => $accrual->curator_id,
                    'entry_type' => CommissionEntryType::Reversal->value,
                    'rule' => $accrual->rule,
                    'rule_snapshot' => $accrual->rule_snapshot,
                    'amount' => $accrual->amount === null
                        ? null
                        : bcsub('0', (string) $accrual->amount, self::SCALE),
                    'currency' => $accrual->currency,
                    'deal_gross_amount' => $deal->gross_amount,
                    'deal_discount_amount' => $deal->discount_amount,
                    'accrued_at' => now(),
                    'reason' => $reason,
                ])->save();

                $this->audit->log(
                    action: PromoAction::CommissionReversed,
                    subjectType: 'curator_commission',
                    subjectId: (string) $reversal->getKey(),
                    actor: null,
                    promoCodeId: $accrual->promo_code_id,
                    changes: ['amount' => $reversal->amount],
                    reason: $reason,
                );

                return $reversal;
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            if (! $this->isDuplicate($e)) {
                throw $e;
            }

            return $this->entry($deal, CommissionEntryType::Reversal);
        }
    }

    /**
     * Отчёт по куратору за период.
     *
     * Считается по закрытым сделкам, а не по начислениям: сделка попадает
     * в отчёт, даже если формула вознаграждения не настроена и суммы нет.
     * Возвращённые сделки исключены из текущего вознаграждения — сторно
     * уменьшает `commission_total`, а сама сделка показана отдельной строкой.
     *
     * @return array<string, mixed>
     */
    public function report(User $curator, ?string $from = null, ?string $to = null): array
    {
        $deals = PromoCodeDeal::query()
            ->select('promo_code_deals.*')
            ->join('promo_codes', 'promo_codes.id', '=', 'promo_code_deals.promo_code_id')
            ->where('promo_codes.curator_id', $curator->id)
            ->where('promo_codes.status', PromoCodeStatus::Closed->value)
            ->when($from !== null, fn ($q) => $q->whereDate('promo_code_deals.closed_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('promo_code_deals.closed_at', '<=', $to))
            ->get();

        $gross = '0.00';
        $discount = '0.00';
        $net = '0.00';

        foreach ($deals as $deal) {
            $gross = bcadd($gross, (string) $deal->gross_amount, self::SCALE);
            $discount = bcadd($discount, (string) $deal->discount_amount, self::SCALE);
            $net = bcadd($net, (string) $deal->net_amount, self::SCALE);
        }

        $entries = CuratorCommission::query()
            ->where('curator_id', $curator->id)
            ->when($from !== null, fn ($q) => $q->whereDate('accrued_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('accrued_at', '<=', $to))
            ->get();

        $commission = '0.00';
        $unpriced = 0;

        foreach ($entries as $entry) {
            if ($entry->amount === null) {
                $unpriced++;

                continue;
            }

            $commission = bcadd($commission, (string) $entry->amount, self::SCALE);
        }

        $rule = $this->rule();

        return [
            'curator' => [
                'id' => $curator->id,
                'name' => $curator->name,
                'email' => $curator->email,
            ],
            'period' => ['from' => $from, 'to' => $to],
            'closed_deals' => $deals->count(),
            'deals_total' => $gross,
            'discount_total' => $discount,
            'net_total' => $net,
            'commission_rule' => $rule['rule'],
            'commission_configured' => $rule['rule'] !== self::RULE_UNCONFIGURED,
            // NULL, а не 0.00: «формула не настроена» и «начислено ноль» —
            // разные ответы, и подменять первый вторым нельзя.
            'commission_total' => $rule['rule'] === self::RULE_UNCONFIGURED ? null : $commission,
            'entries_without_amount' => $unpriced,
            'reversals' => $entries->where('entry_type', CommissionEntryType::Reversal)->count(),
        ];
    }

    /**
     * Действующее правило вознаграждения и его значения.
     *
     * Заполненными не могут быть обе настройки сразу: два правила означали бы
     * два ответа на вопрос «сколько». Приоритет отдан фиксированной ставке,
     * и это состояние помечено в снимке, чтобы расхождение было видно
     * в начислении, а не только в конфиге.
     *
     * @return array{rule: string, fixed_per_closed_deal: ?string, percent_of_platform_commission: ?string, currency: string, conflict: bool}
     */
    public function rule(): array
    {
        $fixed = $this->decimal(config('promo.commission.fixed_per_closed_deal'));
        $percent = $this->decimal(config('promo.commission.percent_of_platform_commission'));

        $rule = match (true) {
            $fixed !== null => self::RULE_FIXED,
            $percent !== null => self::RULE_PERCENT,
            default => self::RULE_UNCONFIGURED,
        };

        return [
            'rule' => $rule,
            'fixed_per_closed_deal' => $fixed,
            'percent_of_platform_commission' => $percent,
            'currency' => (string) config('promo.commission.currency', 'RUB'),
            'conflict' => $fixed !== null && $percent !== null,
        ];
    }

    /**
     * @param  array{rule: string, fixed_per_closed_deal: ?string, percent_of_platform_commission: ?string, currency: string, conflict: bool}  $rule
     */
    private function amount(array $rule, PromoCodeDeal $deal): ?string
    {
        return match ($rule['rule']) {
            self::RULE_FIXED => $rule['fixed_per_closed_deal'],
            // Комиссия платформы приходит вместе со сведениями о сделке;
            // пока её отдельного поля нет, база расчёта — итоговая сумма.
            self::RULE_PERCENT => bcdiv(
                bcmul((string) $deal->net_amount, (string) $rule['percent_of_platform_commission'], 4),
                '100',
                self::SCALE,
            ),
            default => null,
        };
    }

    private function entry(PromoCodeDeal $deal, CommissionEntryType $type): ?CuratorCommission
    {
        return CuratorCommission::query()
            ->where('promo_code_deal_id', $deal->getKey())
            ->where('entry_type', $type->value)
            ->first();
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        // Приводим к строке фиксированной точности, минуя float: настройка
        // приходит из .env строкой и такой же должна дойти до bcmath.
        return bcadd((string) $value, '0', self::SCALE);
    }

    private function isDuplicate(QueryException $e): bool
    {
        if ($e instanceof UniqueConstraintViolationException) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }
}
