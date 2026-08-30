<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromoClientResponse;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Сделка по промокоду — одна на код.
 *
 * `$fillable` пуст: суммы и `reported_by` приходят из проверенного DTO, а не
 * из тела запроса. Доверять `net_amount`, присланному фронтендом, нельзя —
 * он пересчитывается на сервере из `gross_amount` и `discount_amount`.
 */
class PromoCodeDeal extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var array<int, string> */
    protected $fillable = [];

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(CuratorCommission::class);
    }

    protected function casts(): array
    {
        return [
            'deal_date' => 'date',
            'gross_amount' => 'string',
            'discount_amount' => 'string',
            'net_amount' => 'string',
            'on_behalf_of_partner' => 'boolean',
            'client_response' => PromoClientResponse::class,
            'client_responded_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
