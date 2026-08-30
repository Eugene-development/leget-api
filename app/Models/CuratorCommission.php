<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionEntryType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Начисление куратору по закрытой сделке (или его сторно).
 *
 * `rule_snapshot` — не украшение: настройки вознаграждения меняются, а прошлые
 * начисления от этого меняться не должны. Снимок правила рядом с суммой
 * отвечает на вопрос «почему здесь именно столько» через год после расчёта.
 */
class CuratorCommission extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var array<int, string> */
    protected $fillable = [];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(PromoCodeDeal::class, 'promo_code_deal_id');
    }

    public function curator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'curator_id');
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class, 'promo_code_id');
    }

    protected function casts(): array
    {
        return [
            'entry_type' => CommissionEntryType::class,
            'amount' => 'string',
            'deal_gross_amount' => 'string',
            'deal_discount_amount' => 'string',
            'rule_snapshot' => 'array',
            'accrued_at' => 'datetime',
        ];
    }
}
