<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RussianSlug;
use PHPUnit\Framework\TestCase;

final class RussianSlugTest extends TestCase
{
    public function test_transliterates_russian_names_with_readable_pronunciation(): void
    {
        $this->assertSame('shkafy', RussianSlug::make('Шкафы'));
        $this->assertSame('kuhnya-skandi', RussianSlug::make('Кухня «Сканди»'));
        $this->assertSame('prihozhie', RussianSlug::make('Прихожие'));
        $this->assertSame('detskaya-mebel', RussianSlug::make('Детская мебель'));
    }

    public function test_keeps_latin_words_and_normalizes_separators(): void
    {
        $this->assertSame('kuhnya-modern-gloss-2026', RussianSlug::make('Кухня Modern Gloss 2026'));
    }
}
