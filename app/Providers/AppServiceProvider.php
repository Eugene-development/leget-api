<?php

namespace App\Providers;

use App\Auth\VersionedJwtGuard;
use App\Enums\Role;
use App\Events\PromoDealClosed;
use App\Events\PromoDealConfirmed;
use App\Events\PromoDealDisputed;
use App\Events\PromoDealRefunded;
use App\Events\PromoDealReported;
use App\Listeners\AccrueCuratorCommission;
use App\Listeners\RecordOfflineConversion;
use App\Listeners\SendPromoNotifications;
use App\Models\ApplianceBrand;
use App\Models\CatalogBrand;
use App\Models\Category;
use App\Models\User;
use App\Services\BrandTags;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::extend('versioned-jwt', function ($app, $name, array $config) {
            $guard = new VersionedJwtGuard(
                $app['tymon.jwt'],
                $app['auth']->createUserProvider($config['provider']),
                $app['request'],
            );
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        foreach ([Category::class, CatalogBrand::class, ApplianceBrand::class] as $model) {
            $model::saved(fn ($brand) => app(BrandTags::class)->sync($brand));
            $model::deleted(fn () => BrandTags::changed());
        }
        $this->registerRoleGates();
        $this->registerPromoListeners();
    }

    /**
     * Слушатели доменных событий промокодов.
     *
     * Регистрация явная, а не автообнаружением: у одного слушателя несколько
     * методов под разные события, и связь «что на что подписано» должна читаться
     * в одном месте, а не выводиться из сигнатур.
     *
     * Порядок важен ровно в одном месте: начисление вознаграждения должно
     * пройти до записи офлайн-конверсии — конверсия ничего не решает, а сбой
     * в ней не должен оставить сделку без начисления.
     */
    private function registerPromoListeners(): void
    {
        Event::listen(PromoDealReported::class, [SendPromoNotifications::class, 'reported']);
        Event::listen(PromoDealDisputed::class, [SendPromoNotifications::class, 'disputed']);
        Event::listen(PromoDealConfirmed::class, [SendPromoNotifications::class, 'confirmed']);

        Event::listen(PromoDealClosed::class, [AccrueCuratorCommission::class, 'closed']);
        Event::listen(PromoDealClosed::class, [SendPromoNotifications::class, 'closed']);
        Event::listen(PromoDealClosed::class, RecordOfflineConversion::class);

        Event::listen(PromoDealRefunded::class, [AccrueCuratorCommission::class, 'refunded']);
        Event::listen(PromoDealRefunded::class, [SendPromoNotifications::class, 'refunded']);
    }

    /**
     * Способности ролей → Gate, чтобы маршруты закрывались штатным `can:`.
     *
     * Права берутся из кода (`Role::abilities()`), а не из таблицы: проверку
     * всё равно пишет разработчик вместе с маршрутом, и таблица прав была бы
     * вторым источником правды, расходящимся с routes/web.php.
     *
     * Проверять надо способность, а не имя роли — тогда доступ второй роли
     * добавляется строкой в enum, а не поиском по коду.
     */
    private function registerRoleGates(): void
    {
        foreach (Role::abilityNames() as $ability) {
            Gate::define($ability, static fn (User $user): bool => $user->hasAbility($ability));
        }
    }
}
