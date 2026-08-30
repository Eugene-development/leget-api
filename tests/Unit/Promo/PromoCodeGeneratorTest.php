<?php

declare(strict_types=1);

namespace Tests\Unit\Promo;

use App\Services\PromoCodeGenerator;
use Tests\TestCase;

/**
 * Код даёт скидку — предсказуемость означает бесплатную скидку тому,
 * кто перебрал соседние коды.
 */
class PromoCodeGeneratorTest extends TestCase
{
    public function test_codes_do_not_repeat_across_a_large_batch(): void
    {
        $generator = new PromoCodeGenerator;

        $codes = [];
        for ($i = 0; $i < 2000; $i++) {
            $codes[] = $generator->candidate();
        }

        $this->assertCount(2000, array_unique($codes), 'Сгенерированные коды повторились.');
    }

    /**
     * Код называют вслух и переписывают с бумаги: символы, которые путают
     * с другими (0/O, 1/I/L), в алфавите не участвуют.
     */
    public function test_code_uses_the_unambiguous_alphabet_and_declared_length(): void
    {
        $generator = new PromoCodeGenerator;
        $alphabet = (string) config('promo.code.alphabet');
        $length = (int) config('promo.code.length');
        $prefix = (string) config('promo.code.prefix');

        $code = $generator->candidate();

        $this->assertStringStartsWith($prefix.'-', $code);

        $body = substr($code, strlen($prefix) + 1);
        $this->assertSame($length, strlen($body));

        foreach (str_split($body) as $char) {
            $this->assertStringContainsString($char, $alphabet, "Символ {$char} вне алфавита.");
        }

        $this->assertDoesNotMatchRegularExpression('/[01OIL]/', $body);
    }

    /**
     * В коде нет ничего восстановимого: его печатают в договоре у партнёра,
     * и связь с клиентом или рекламой утекла бы вместе с бумагой.
     */
    public function test_code_carries_no_identifiers(): void
    {
        $generator = new PromoCodeGenerator;

        $codes = array_map(static fn (): string => $generator->candidate(), range(1, 50));

        // Ни один код не совпадает с соседним ни началом, ни хвостом —
        // счётчика или общей части в нём нет.
        $bodies = array_map(static fn (string $c): string => substr($c, 3), $codes);
        $prefixes = array_map(static fn (string $b): string => substr($b, 0, 4), $bodies);

        $this->assertGreaterThan(40, count(array_unique($prefixes)));
    }

    /** Распределение символов не должно быть вырожденным. */
    public function test_distribution_covers_the_alphabet(): void
    {
        $generator = new PromoCodeGenerator;
        $seen = [];

        for ($i = 0; $i < 500; $i++) {
            foreach (str_split(substr($generator->candidate(), 3)) as $char) {
                $seen[$char] = true;
            }
        }

        $this->assertSame(
            strlen((string) config('promo.code.alphabet')),
            count($seen),
            'Часть алфавита ни разу не встретилась — генератор смещён.',
        );
    }
}
