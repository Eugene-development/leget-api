<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

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
     * Создаёт счёт, присвоив ему уникальный номер.
     *
     * Номер вычисляется как «последний в этом месяце + 1», поэтому два
     * одновременных запроса могут получить одно и то же значение и упереться
     * в UNIQUE-индекс. В этом случае номер пересчитывается и вставка
     * повторяется — до $attempts раз.
     *
     * @param  array<string, mixed>  $attributes  Атрибуты счёта без 'number'.
     */
    public static function createWithUniqueNumber(array $attributes, int $attempts = 5): self
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $attributes['number'] = static::generateNumber();

                return static::create($attributes);
            } catch (QueryException $e) {
                if (! static::isDuplicateNumberError($e)) {
                    throw $e;
                }

                $lastException = $e;
                // Небольшая случайная пауза, чтобы конкурирующие запросы разошлись
                usleep(random_int(10_000, 60_000));
            }
        }

        throw $lastException;
    }

    /**
     * Является ли ошибка нарушением уникальности (MySQL 23000 / PostgreSQL 23505).
     */
    private static function isDuplicateNumberError(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }

    /**
     * Генерирует уникальный номер счёта формата YYYYMM-XXXXX.
     */
    public static function generateNumber(): string
    {
        $prefix = now()->format('Ym') . '-';
        $last   = static::where('number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('number');

        $seq = $last
            ? ((int) substr($last, strlen($prefix))) + 1
            : 1;

        return $prefix . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
