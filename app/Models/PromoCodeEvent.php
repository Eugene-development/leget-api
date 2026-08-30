<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromoAction;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Запись журнала аудита. Создаётся один раз и больше не меняется.
 *
 * Неизменяемость держится тремя вещами сразу: у таблицы нет `updated_at`,
 * модель роняет `update()` и `delete()` исключением, и маршрута на удаление
 * не существует. Одного соглашения было бы мало — журнал ценен ровно настолько,
 * насколько его нельзя подчистить тому, кого он изобличает.
 */
class PromoCodeEvent extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    /** Событие произошло один раз — обновлять нечего. */
    public const UPDATED_AT = null;

    /** @var array<int, string> */
    protected $fillable = [];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class, 'promo_code_id');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('Записи журнала аудита неизменяемы.');
        }

        return parent::update($attributes, $options);
    }

    public function delete(): bool
    {
        throw new LogicException('Записи журнала аудита не удаляются.');
    }

    protected function casts(): array
    {
        return [
            'action' => PromoAction::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
