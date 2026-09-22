<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['wallet_id', 'license_id', 'billing_date', 'amount', 'type', 'description'])]
class Transaction extends Model
{
    public function getOccurredAtAttribute(): ?string
    {
        return $this->created_at?->toIso8601String();
    }

    /**
     * Получить кошелёк, к которому относится транзакция.
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * Приведение типов атрибутов.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }
}
