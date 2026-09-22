<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Tenant;
use App\Services\BillingService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class CancelLicense
{
    /**
     * Отмена лицензии клиентом.
     *
     * Устанавливает статус 'cancelled' и is_active = false для лицензии,
     * а также для связанного тенанта (если существует), что останавливает
     * ежедневное списание средств с баланса.
     *
     * @param  mixed  $root
     * @param  array{id: string}  $args
     *
     * @throws GraphQLException
     */
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
            if ($license->status === 'cancelled') {
                return $license;
            }
            app(BillingService::class)->settleLockedLicense($license);
            // Отменяем лицензию
            $license->update([
                'status' => 'cancelled',
                'is_active' => false,
            ]);

            // Останавливаем биллинг: деактивируем связанный тенант по домену
            $tenant = Tenant::where('domain', $license->domain)
                ->where('user_id', $license->user_id)
                ->first();

            if ($tenant) {
                $tenant->update([
                    'status' => 'cancelled',
                    'is_active' => false,
                ]);

                Log::info('Биллинг: тенант деактивирован при отмене лицензии', [
                    'license_id' => $license->id,
                    'tenant_id' => $tenant->id,
                    'domain' => $license->domain,
                    'user_id' => $license->user_id,
                ]);
            }

            return $license;
        }, 3);

        // Инвалидируем кэш страниц сайта
        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException) {
            Cache::flush();
        }

        Log::info('Лицензия отменена клиентом', [
            'license_id' => $license->id,
            'domain' => $license->domain,
            'user_id' => $license->user_id,
        ]);

        return $license->fresh();
    }
}
