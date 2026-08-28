<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the identifier that will be stored in the JWT subject claim.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return a key value array of custom claims to add to the JWT payload.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /**
     * Заявка на партнёрство, она же профиль партнёра.
     *
     * Существует и у клиента: заявка подаётся до одобрения, и роль в этот
     * момент ещё `client`. Наличие профиля — не право на Офис, право даёт роль.
     */
    public function partnerProfile(): HasOne
    {
        return $this->hasOne(PartnerProfile::class);
    }

    /**
     * Получить кошелёк пользователя.
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Получить все сайты (тенанты) пользователя.
     */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /**
     * Получить все лицензии пользователя.
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Каст в enum: неизвестное значение в колонке роняет гидрацию
            // ValueError'ом, а не тихо превращается в «роль, которой нет».
            // В #[Fillable] роли нет намеренно — иначе её можно было бы
            // присвоить телом запроса.
            'role' => Role::class,
        ];
    }
}
