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
#[Hidden(['password', 'remember_token', 'token_version'])]
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
        return ['token_version' => (int) $this->token_version];
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
     * Рекламная атрибуция клиента — откуда он пришёл.
     *
     * Одна на пользователя. Пишется только `AttributionRecorder` по токену
     * самого клиента: ни куратор, ни партнёр в неё не пишут, иначе источник,
     * за который куратору платят, мог бы переписать он сам.
     */
    public function attribution(): HasOne
    {
        return $this->hasOne(AdAttribution::class);
    }

    /** Промокоды, выданные этому пользователю как клиенту. */
    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class, 'client_id');
    }

    /** Промокоды, которые он ведёт как куратор. */
    public function curatedPromoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class, 'curator_id');
    }

    /** Промокоды, назначенные ему как партнёру. */
    public function assignedPromoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class, 'partner_id');
    }

    /**
     * Уведомления кабинета.
     *
     * Имя не `notifications()`: так называется morphMany из трейта Notifiable
     * (штатные уведомления Laravel), и перекрытие сломало бы `$user->notify()`.
     */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
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
    public function roleNames(): array
    {
        $role = $this->role ?? Role::Client;
        $roles = [$role->value];
        if ($this->university_enrolled_at !== null && ! in_array('student', $roles, true)) {
            $roles[] = 'student';
        }

        return $roles;
    }

    /** Preserve education when an authorized operation assigns the non-student role. */
    public function setPrimaryRole(Role $role): self
    {
        if ($this->role === Role::Student && $role !== Role::Student) {
            $this->forceFill(['university_enrolled_at' => $this->university_enrolled_at ?? now()]);
        }

        return $this->forceFill(['role' => $role]);
    }

    public function hasAbility(string $ability): bool
    {
        foreach ($this->roleNames() as $role) {
            if (Role::from($role)->can($ability)) {
                return true;
            }
        }

        return false;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'university_enrolled_at' => 'datetime',
            'password' => 'hashed',
            'token_version' => 'integer',
            // Каст в enum: неизвестное значение в колонке роняет гидрацию
            // ValueError'ом, а не тихо превращается в «роль, которой нет».
            // В #[Fillable] роли нет намеренно — иначе её можно было бы
            // присвоить телом запроса.
            'role' => Role::class,
        ];
    }
}
