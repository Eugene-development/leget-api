<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_id',
        'number',
        'amount',
        'status',
        'company_name',
        'inn',
        'paid_at',
    ];

    protected $casts = [
        'amount'  => 'string',
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * Генерирует уникальный номер счёта формата INV-YYYYMM-XXXXX.
     */
    public static function generateNumber(): string
    {
        $prefix = 'INV-' . now()->format('Ym') . '-';
        $last   = static::where('number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('number');

        $seq = $last
            ? ((int) substr($last, strlen($prefix))) + 1
            : 1;

        return $prefix . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
