<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Tenant;
use App\Services\BillingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class DeleteLicense
{
    public function __invoke($root, array $args, GraphQLContext $context): array
    {
        $license = DB::transaction(function () use ($args, $context) {
            $license = License::withTrashed()->whereKey($args['id'])->lockForUpdate()->first();
            if (! $license) {
                throw new GraphQLException('License not found.', 'VALIDATION');
            }
            if ($context->user()->id !== $license->user_id) {
                throw new GraphQLException('This action is unauthorized.', 'AUTHORIZATION');
            }
            if ($license->trashed()) {
                return $license;
            }
            app(BillingService::class)->settleLockedLicense($license);
            $license->update(['status' => 'cancelled', 'is_active' => false]);
            Tenant::where('domain', $license->domain)->where('user_id', $license->user_id)
                ->update(['status' => 'cancelled', 'is_active' => false]);
            // Retain pages, components, payment history and the domain for support recovery.
            $license->delete();

            return $license;
        }, 3);

        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException) {
            Cache::flush();
        }

        return ['id' => $license->id, 'deleted' => true];
    }
}
