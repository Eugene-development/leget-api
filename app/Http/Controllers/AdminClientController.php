<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    public function assignCurator(Request $request, int $id)
    {
        $input = $request->validate(['reason' => 'required|string|min:3|max:2000']);

        return DB::transaction(function () use ($request, $id, $input) {
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasAbility('users.curate'), 403);
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($user->role, [Role::Client, Role::Student, Role::Curator], true), 409, 'Нельзя заменить роль сотрудника, партнёра или администратора сайта.');
            if ($user->role !== Role::Curator) {
                $before = $user->role->value;
                $user->forceFill(['role' => Role::Curator, 'university_enrolled_at' => $user->university_enrolled_at ?? ($user->role === Role::Student ? now() : null)])->save();
                Log::notice('LEGET: curator assigned', ['actor_id' => $actor->id, 'user_id' => $id, 'before' => $before, 'reason' => $input['reason']]);
            }

            return response()->json(['success' => true, 'role' => 'curator']);
        }, 3);
    }

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
                'role' => $user->role?->value ?? 'client',
                'roles' => $user->roleNames(),
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
