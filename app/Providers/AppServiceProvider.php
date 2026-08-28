<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
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
        $this->registerRoleGates();
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
            Gate::define($ability, static fn (User $user): bool => ($user->role ?? Role::Client)->can($ability));
        }
    }
}
