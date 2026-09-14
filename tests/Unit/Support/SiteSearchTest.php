<?php

namespace Tests\Unit\Support;

use App\Services\SiteSearch;
use PHPUnit\Framework\TestCase;

class SiteSearchTest extends TestCase
{
    public function test_extracts_rich_text_but_not_editor_metadata_or_disabled_items(): void
    {
        $text = SiteSearch::text([
            'title' => '<p>Кухни &amp; фасады</p>',
            'description' => '<script>alert(1)</script><p>Первый</p><p>Второй</p>',
            'items' => [['title' => 'Скрыто', 'enabled' => false], ['title' => 'Открыто']],
            'meta' => ['name' => 'Личные данные'],
            'object_address' => 'Квартира 99',
            'href' => 'https://example.com/secret',
            'icon' => '<svg>icon</svg>',
        ]);
        $this->assertSame('Кухни & фасады Первый Второй Открыто', $text);
    }

    public function test_matches_russian_case_yo_multiple_words_and_snippet(): void
    {
        $documents = [['title' => 'Мебель', 'url' => '/mebel', 'text' => str_repeat('Текст ', 30).'Тёмная кухня на заказ']];
        $result = SiteSearch::search($documents, '  ТЕМНАЯ кухня  ');
        $this->assertSame(1, $result['total']);
        $this->assertStringContainsString('Тёмная кухня', $result['items'][0]['snippet']);
        $this->assertSame(0, SiteSearch::search($documents, 'ку')['total']);
        $this->assertSame(0, SiteSearch::search($documents, str_repeat('а', 121))['total']);
        $this->assertSame(0, SiteSearch::search($documents, 'кухня отсутствует')['total']);
    }

    public function test_paginates_without_losing_total_and_ranks_title_matches_first(): void
    {
        $documents = [];
        for ($i = 0; $i < 25; $i++) {
            $documents[] = ['title' => 'Страница', 'url' => '/page-'.$i, 'text' => 'Доставка по городу'];
        }
        $documents[] = ['title' => 'Доставка', 'url' => '/delivery', 'text' => 'Условия'];
        $first = SiteSearch::search($documents, 'доставка');
        $next = SiteSearch::search($documents, 'доставка', 20);
        $this->assertSame(26, $first['total']);
        $this->assertCount(20, $first['items']);
        $this->assertCount(6, $next['items']);
        $this->assertSame('/delivery', $first['items'][0]['url']);
        $this->assertSame([], array_intersect(array_column($first['items'], 'url'), array_column($next['items'], 'url')));
    }

    public function test_matches_latin_and_cyrillic_lookalikes_without_changing_display_text(): void
    {
        $documents = [
            ['title' => 'eeeee2', 'url' => '/mebel/kuhni/eeeee2', 'text' => 'Проект eeeee2 на заказ'],
        ];
        foreach (['eeee', 'ееее', 'ЕЕЕЕ', 'eеeе'] as $query) {
            $result = SiteSearch::search($documents, $query);
            $this->assertSame(1, $result['total'], $query);
            $this->assertSame('eeeee2', $result['items'][0]['title']);
            $this->assertSame('/mebel/kuhni/eeeee2', $result['items'][0]['url']);
            $this->assertSame('Проект eeeee2 на заказ', $result['items'][0]['snippet']);
        }
        $this->assertSame(0, SiteSearch::search($documents, 'ee')['total']);
        $this->assertSame(0, SiteSearch::search($documents, 'tttt')['total']);
        $reverse = [['title' => 'еееее2', 'url' => '/project', 'text' => 'Описание']];
        $this->assertSame(1, SiteSearch::search($reverse, 'eeee')['total']);
    }

    public function test_excludes_non_public_or_unsafe_routes(): void
    {
        foreach (['//example.com', '/\\example.com', '/mebel/{category}', '/404', '/admin/users', '/cabinet', '__global__', '/path#fragment'] as $path) {
            $this->assertFalse(SiteSearch::publicPath($path), $path);
        }
        $this->assertTrue(SiteSearch::publicPath('/mebel/kuhni'));
    }
}
