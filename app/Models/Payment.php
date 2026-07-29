<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Онлайн-платёж для пополнения баланса.
 *
 * @property string $status
 * @property string $amount
 * @property ?int   $transaction_id
 */
class Payment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_WAITING_FOR_CAPTURE = 'waiting_for_capture';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'user_id',
        'wallet_id',
        'transaction_id',
        'provider',
        'provider_payment_id',
        'idempotence_key',
        'amount',
        'currency',
        'status',
        'cancellation_reason',
        'paid_at',
        'provider_payload',
    ];

    protected $casts = [
        'amount'           => 'string',
        'paid_at'          => 'datetime',
        'provider_payload' => 'array',
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
     * Транзакция зачисления на баланс. NULL, пока деньги не зачислены.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Деньги по этому платежу уже зачислены на баланс.
     */
    public function isCredited(): bool
    {
        return $this->transaction_id !== null;
    }

    /**
     * Платёж ещё может завершиться успешно (ожидает действий пользователя).
     */
    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_WAITING_FOR_CAPTURE], true);
    }
}
