<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Список регистраций для страницы «Мои клиенты» в панели администратора.
 *
 * Список — все зарегистрированные, кроме админов платформы: страница
 * показывает регистрации, и появление третьей роли не должно молча убирать
 * людей из неё. Фильтр идёт по колонке `users.role` (см. App\Enums\Role);
 * allowlist LEGET_ADMIN_EMAILS в обработке запроса больше не участвует.
 */
final class AdminClientController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|nullable|string|max:255',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $search = trim((string) ($validated['search'] ?? ''));

        $clients = $this->clients()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('region', 'like', $like);
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage)
            ->through(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'region' => $user->region,
                'email_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'clients' => $clients,
            'summary' => [
                'total' => $this->clients()->count(),
                'verified' => $this->clients()->whereNotNull('email_verified_at')->count(),
                'last_30_days' => $this->clients()->where('created_at', '>=', now()->subDays(30))->count(),
            ],
        ]);
    }

    /**
     * @return Builder<User>
     */
    private function clients(): Builder
    {
        return User::query()->whereNot('role', Role::Superadmin->value);
    }
}
