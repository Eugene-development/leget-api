<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Tenant;
use App\Services\BillingService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class RestoreLicense
{
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): License
    {
        $license = DB::transaction(function () use ($args, $context) {
            $license = License::whereKey($args['id'])->lockForUpdate()->first();
            if (! $license) {
                throw new GraphQLException('License not found.', 'VALIDATION');
            }
            if ($context->user()->id !== $license->user_id) {
                throw new GraphQLException('This action is unauthorized.', 'AUTHORIZATION');
            }
            // A retry must not postpone the next debit or restart a trial.
            if ($license->status === 'active' && $license->is_active) {
                return $license;
            }
            if ($license->status !== 'cancelled') {
                throw new GraphQLException('Восстановить можно только отменённую лицензию.', 'VALIDATION');
            }

            $startsAt = Carbon::now('Europe/Moscow');
            // Keep any remaining original trial; never grant a new one.
            if ($license->billing_started_at?->gt($startsAt)) {
                $startsAt = $license->billing_started_at->copy();
            }
            $license->update([
                'status' => 'active',
                'is_active' => true,
                'billing_started_at' => $license->billing_started_at ?? $startsAt,
                // Skip the cancelled interval even if the old cursor is months behind.
                'next_billing_date' => BillingService::firstBillingDate($startsAt),
            ]);
            Tenant::where('domain', $license->domain)
                ->where('user_id', $license->user_id)
                ->where('status', 'cancelled')
                ->update(['status' => 'active', 'is_active' => true]);

            return $license;
        }, 3);

        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException) {
            Cache::flush();
        }

        return $license->fresh();
    }
}
