<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Внутреннее уведомление личного кабинета.
 *
 * Единственный канал, который у платформы действительно есть. Почта, SMS
 * и мессенджеры подключаются вторым слушателем доменного события, а не
 * подменой этой модели: имитировать отправку в несуществующий канал хуже,
 * чем честно её не делать.
 */
class UserNotification extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var array<int, string> */
    protected $fillable = [
        'type',
        'title',
        'body',
        'payload',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
