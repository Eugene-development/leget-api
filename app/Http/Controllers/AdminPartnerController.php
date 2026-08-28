<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PartnerStatus;
use App\Enums\Role;
use App\Models\PartnerProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Разбор заявок на партнёрство для панели платформы.
 *
 * Одобрение — единственное место, где роль `partner` появляется у человека
 * штатным путём. Поэтому статус заявки и роль меняются здесь одной транзакцией:
 * разойдись они, одобренный партнёр остался бы без Офиса, а лишённый роли
 * числился бы одобренным.
 */
final class AdminPartnerController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(PartnerStatus::class)],
        ]);

        $status = $validated['status'] ?? null;

        $applications = $this->applications()
            ->when($status !== null, static fn (Builder $q) => $q->where('status', $status))
            // Сначала непросмотренные: очередь разбора, а не архив.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [PartnerStatus::Pending->value])
            ->latest('created_at')
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->through(fn (PartnerProfile $profile): array => $this->present($profile));

        return response()->json([
            'success' => true,
            'applications' => $applications,
            'summary' => [
                'pending' => $this->applications()->where('status', PartnerStatus::Pending->value)->count(),
                'approved' => $this->applications()->where('status', PartnerStatus::Approved->value)->count(),
                'rejected' => $this->applications()->where('status', PartnerStatus::Rejected->value)->count(),
            ],
        ]);
    }

    public function approve(Request $request, string $id)
    {
        $profile = $this->applications()->find($id);

        if (! $profile instanceof PartnerProfile) {
            return $this->missing();
        }

        DB::transaction(function () use ($profile, $request): void {
            $profile->forceFill([
                'status' => PartnerStatus::Approved,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => null,
            ])->save();

            // Роль — вторая половина того же решения, поэтому в той же транзакции.
            $profile->user->forceFill(['role' => Role::Partner->value])->save();
        });

        return response()->json([
            'success' => true,
            'application' => $this->present($profile->fresh(['user', 'reviewer'])),
        ]);
    }

    public function reject(Request $request, string $id)
    {
        $validated = $request->validate([
            'note' => 'sometimes|nullable|string|max:2000',
        ]);

        $profile = $this->applications()->find($id);

        if (! $profile instanceof PartnerProfile) {
            return $this->missing();
        }

        DB::transaction(function () use ($profile, $request, $validated): void {
            $wasApproved = $profile->status === PartnerStatus::Approved;

            $profile->forceFill([
                'status' => PartnerStatus::Rejected,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $validated['note'] ?? null,
            ])->save();

            // Отзыв одобрения снимает и роль: иначе человек потерял бы статус
            // на бумаге, но сохранил Офис.
            if ($wasApproved) {
                $profile->user->forceFill(['role' => Role::Client->value])->save();
            }
        });

        return response()->json([
            'success' => true,
            'application' => $this->present($profile->fresh(['user', 'reviewer'])),
        ]);
    }

    /**
     * @return Builder<PartnerProfile>
     */
    private function applications(): Builder
    {
        return PartnerProfile::query()->with(['user', 'reviewer']);
    }

    private function missing()
    {
        return response()->json([
            'success' => false,
            'message' => 'Заявка не найдена.',
        ], Response::HTTP_NOT_FOUND);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PartnerProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'status' => $profile->status->value,
            'status_label' => $profile->status->label(),
            'partner_type' => $profile->partner_type->value,
            'partner_type_label' => $profile->partner_type->label(),
            'company' => $profile->company,
            'inn' => $profile->inn,
            'website' => $profile->website,
            'city' => $profile->city,
            'comment' => $profile->comment,
            'created_at' => $profile->created_at?->toIso8601String(),
            'reviewed_at' => $profile->reviewed_at?->toIso8601String(),
            'review_note' => $profile->review_note,
            'reviewer' => $profile->reviewer?->email,
            'applicant' => [
                'id' => $profile->user?->id,
                'name' => $profile->user?->name,
                'email' => $profile->user?->email,
                'phone' => $profile->user?->phone,
                'role' => $profile->user?->role?->value,
            ],
        ];
    }
}
