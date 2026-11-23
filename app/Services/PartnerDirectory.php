<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Enums\Role;
use App\Models\PartnerProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

final class PartnerDirectory
{
    public const PAGE_SIZE = 24;

    public function published(): Builder
    {
        return PartnerProfile::query()
            ->where('status', PartnerStatus::Approved->value)
            ->whereIn('partner_type', PartnerType::directoryValues())
            ->whereNotNull('directory_published_at')
            ->whereNotNull('directory_name')
            ->whereHas('user', fn (Builder $user) => $user->where('role', Role::Partner->value));
    }

    public function path(PartnerProfile $profile): string
    {
        return '/b2b/'.strtolower($profile->id);
    }

    public function summary(PartnerProfile $profile): array
    {
        return [
            'path' => $this->path($profile),
            'name' => $profile->directory_name,
            'typeLabel' => $profile->partner_type->label(),
            'logoUrl' => $profile->directory_card['logoUrl'] ?? null,
            'city' => $profile->directory_card['city'] ?? null,
            'description' => mb_strimwidth($profile->directory_card['description'] ?? '', 0, 250, '…'),
        ];
    }

    public function detail(PartnerProfile $profile): array
    {
        return [
            'description' => $profile->directory_card['description'] ?? '',
        ] + $this->summary($profile)
            + Arr::only($profile->directory_card ?? [], ['address', 'phone', 'email', 'website']);
    }
}
