<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromoCodeStatus;
use App\Enums\PromoDiscountType;
use App\Enums\PromoSubjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Промокод.
 *
 * `$fillable` пуст намеренно: ни один атрибут не приходит из тела запроса
 * массовым присвоением. `client_id`, `curator_id`, `partner_id` и `status`
 * ставит только `PromoCodeService` — иначе подстановка чужого `client_id`
 * в JSON превращалась бы в чужой промокод, а `status: closed` в теле запроса —
 * в закрытую сделку без единой проверки.
 *
 * Код (`code`) генерируется криптостойким источником и не содержит ни
 * `client_id`, ни `yclid`, ни UTM: его называют вслух и печатают в договоре.
 */
class PromoCode extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Массовое присвоение запрещено целиком. Смотри комментарий класса.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function curator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'curator_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function attribution(): BelongsTo
    {
        return $this->belongsTo(AdAttribution::class, 'attribution_id');
    }

    public function deal(): HasOne
    {
        return $this->hasOne(PromoCodeDeal::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PromoCodeEvent::class, 'promo_code_id');
    }

    /**
     * Истёк ли срок действия по календарю.
     *
     * Отдельно от статуса: строка `expired` появляется, когда код трогают
     * (или когда пройдёт команда `promo:expire`), а календарь может обогнать
     * её на сутки. Проверять надо оба условия — см. PromoCodeService::assertUsable.
     */
    public function isPastExpiry(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasStarted(): bool
    {
        return $this->starts_at === null || ! $this->starts_at->isFuture();
    }

    /**
     * Промокоды, доступные конкретному пользователю в его роли.
     *
     * Сужение выборки живёт в модели, а не в каждом контроллере: «клиент видит
     * только свои» обязано быть одним выражением, иначе следующий маршрут
     * забудет условие и покажет чужие.
     *
     * @param  Builder<PromoCode>  $query
     * @return Builder<PromoCode>
     */
    public function scopeVisibleToClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * @param  Builder<PromoCode>  $query
     * @return Builder<PromoCode>
     */
    public function scopeVisibleToPartner(Builder $query, int $partnerId): Builder
    {
        // До активации код партнёру не виден — он ещё черновик куратора.
        return $query->where('partner_id', $partnerId)
            ->where('status', '!=', PromoCodeStatus::Created->value);
    }

    /**
     * @param  Builder<PromoCode>  $query
     * @return Builder<PromoCode>
     */
    public function scopeVisibleToCurator(Builder $query, int $curatorId): Builder
    {
        return $query->where('curator_id', $curatorId);
    }

    protected function casts(): array
    {
        return [
            'status' => PromoCodeStatus::class,
            'discount_type' => PromoDiscountType::class,
            'subject_type' => PromoSubjectType::class,
            // Строка, а не float: денежные значения не должны проходить через
            // двоичную дробь ни на одном участке пути. Расчёты идут bcmath.
            'discount_value' => 'string',
            'minimum_order_amount' => 'string',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'presented_at' => 'datetime',
            'order_created_at' => 'datetime',
            'deal_reported_at' => 'datetime',
            'client_confirmed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'closed_at' => 'datetime',
            'disputed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }
}
