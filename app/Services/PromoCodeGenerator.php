<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PromoCode;
use RuntimeException;

/**
 * Генератор публичного кода.
 *
 * Источник случайности — `random_int()`, то есть CSPRNG операционной системы.
 * `rand()`/`mt_rand()` здесь недопустимы: их состояние восстанавливается
 * по нескольким выданным значениям, а промокод даёт скидку — предсказуемость
 * означает бесплатную скидку любому, кто перебрал соседние коды.
 *
 * В коде нет ни `client_id`, ни `yclid`, ни UTM. Код называют вслух по телефону
 * и печатают в договоре у партнёра: любая восстановимая из него внутренняя
 * связь утекла бы наружу вместе с бумагой.
 *
 * Уникальность обеспечивает индекс БД, а не эта проверка. Цикл здесь нужен,
 * чтобы не отдавать пользователю ошибку из-за астрономически редкого
 * совпадения; настоящая гарантия — `unique` на `promo_codes.code`, и вставка
 * всё равно повторяется при нарушении индекса (см. PromoCodeService).
 */
final class PromoCodeGenerator
{
    private const MAX_ATTEMPTS = 8;

    public function generate(): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $code = $this->candidate();

            if (! PromoCode::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Не удалось подобрать свободный промокод.');
    }

    /** Кандидат без проверки уникальности — нужен тестам на распределение. */
    public function candidate(): string
    {
        $alphabet = (string) config('promo.code.alphabet');
        $length = max(6, (int) config('promo.code.length'));
        $prefix = (string) config('promo.code.prefix');

        $max = strlen($alphabet) - 1;
        $body = '';

        for ($i = 0; $i < $length; $i++) {
            $body .= $alphabet[random_int(0, $max)];
        }

        return $prefix === '' ? $body : $prefix.'-'.$body;
    }
}
