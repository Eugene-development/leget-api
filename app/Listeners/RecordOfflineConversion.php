<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PromoDealClosed;
use App\Models\Conversion;
use App\Models\PromoCode;
use App\Support\YandexConversionExport;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Закрытая сделка → строка в реестре конверсий.
 *
 * Это outbox, а не отправка. Реальная передача офлайн-конверсий в Яндекс
 * не выполняется: у платформы нет ни credentials, ни согласованного API,
 * и выдумывать запрос к чужому сервису здесь нечего. Строка ложится в тот же
 * реестр `conversions`, который уже выгружается CSV-файлом, совместимым
 * с Центром конверсий, — то есть путь наружу существует и проходит через
 * человека, а не через несуществующую интеграцию.
 *
 * Связь с рекламой (yclid) берётся из атрибуции клиента, а не из тела запроса:
 * ни партнёр, ни куратор рекламных идентификаторов не видят и не задают.
 *
 * Идемпотентность — уникальный индекс на `conversions.promo_code_deal_id`.
 */
final class RecordOfflineConversion
{
    public function __construct(private readonly YandexConversionExport $export) {}

    public function handle(PromoDealClosed $event): void
    {
        if (! config('promo.offline_conversions.enabled')) {
            return;
        }

        $deal = $event->deal;
        $promo = $deal->promoCode;

        if (! $promo instanceof PromoCode) {
            return;
        }

        $client = $promo->client;

        if ($client === null) {
            return;
        }

        // Яндексу нужен контакт: телефон или email. Нормализация — та же,
        // что у ручного ввода офлайн-конверсии в панели.
        $phone = $this->export->normalizePhone((string) $client->phone);
        $email = $this->export->normalizeEmail((string) $client->email);

        if ($phone === null && $email === null) {
            return;
        }

        $yclid = $promo->attribution?->first_yclid ?? $promo->attribution?->last_yclid;

        try {
            Conversion::query()->create([
                'channel' => Conversion::CHANNEL_OFFLINE,
                'type' => Conversion::TYPE_PROMO_DEAL,
                'name' => (string) $client->name,
                'contact' => $phone ?? $email,
                // Колонка ad_id в реестре — это идентификатор визита Яндекса
                // (yclid), а не ID объявления. Имя историческое.
                'ad_id' => is_string($yclid) && preg_match('/^\d{1,32}$/', $yclid) === 1 ? $yclid : null,
                'comment' => sprintf(
                    'Сделка по промокоду %s: %s %s (скидка %s).',
                    $promo->code,
                    $deal->net_amount,
                    $deal->currency,
                    $deal->discount_amount,
                ),
                'promo_code_deal_id' => $deal->getKey(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Повторное закрытие или переигранное событие — конверсия уже есть.
        } catch (QueryException $e) {
            $message = strtolower($e->getMessage());

            if (! str_contains($message, 'unique') && ! str_contains($message, 'duplicate')) {
                throw $e;
            }
        }
    }
}
