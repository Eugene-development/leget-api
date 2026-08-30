<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Уведомления кабинета — свои и только свои.
 *
 * Открыт любой вошедшей роли: уведомления получают и клиент, и партнёр,
 * и куратор, и администратор. Границей служит не способность, а выборка —
 * `user_id` берётся из токена, и запросить чужие нечем.
 */
final class UserNotificationController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
            'unread' => 'sometimes|boolean',
        ]);

        $notifications = $request->user()->appNotifications()
            ->when((bool) ($validated['unread'] ?? false), fn ($q) => $q->whereNull('read_at'))
            ->latest('created_at')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'notifications' => $notifications,
            'unread' => $request->user()->appNotifications()->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->appNotifications()->whereKey($id)->first();

        if (! $notification instanceof UserNotification) {
            return response()->json([
                'success' => false,
                'message' => 'Уведомление не найдено.',
            ], Response::HTTP_NOT_FOUND);
        }

        // Идемпотентно: повторный вызов не меняет момент прочтения.
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['success' => true, 'notification' => $notification]);
    }
}
