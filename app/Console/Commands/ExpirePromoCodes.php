<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PromoCodeStatus;
use App\Models\PromoCode;
use App\Services\PromoCodeService;
use Illuminate\Console\Command;

/**
 * Перевести просроченные промокоды в состояние `expired`.
 *
 * Срок истекает сам по себе, а состояние в БД — нет. Без этой команды код
 * оставался бы «активированным» в списках до первого обращения к нему, и
 * партнёр видел бы предложение, которого больше нет. Обращение к коду тоже
 * переводит его в `expired` (см. PromoCodeService::assertNotExpired) — команда
 * закрывает те, к которым никто не обратился.
 *
 * Актора у события нет: истечение срока не чьё-то действие, и приписывать его
 * запустившему команду было бы ложью в журнале.
 */
final class ExpirePromoCodes extends Command
{
    protected $signature = 'promo:expire {--limit=500 : Сколько кодов обработать за прогон}';

    protected $description = 'Пометить истёкшие промокоды состоянием expired';

    public function handle(PromoCodeService $service): int
    {
        $codes = PromoCode::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->whereNotIn('status', [
                PromoCodeStatus::Closed->value,
                PromoCodeStatus::Cancelled->value,
                PromoCodeStatus::Refunded->value,
                PromoCodeStatus::Expired->value,
                // Заявленную и подтверждённую сделку срок кода уже не отменяет:
                // товар куплен, скидка предоставлена. Истечь может только то,
                // чем не успели воспользоваться.
                PromoCodeStatus::DealReported->value,
                PromoCodeStatus::ClientConfirmed->value,
                PromoCodeStatus::Disputed->value,
            ])
            ->limit((int) $this->option('limit'))
            ->get();

        $expired = 0;

        foreach ($codes as $code) {
            $service->expire($code);
            $expired++;
        }

        $this->info("Помечено истёкшими: {$expired}.");

        return self::SUCCESS;
    }
}
