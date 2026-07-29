<?php

namespace Tests\Feature\PageComponent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies that the down() method of the page_components migration
 * drops the page_components table.
 *
 * Validates: Requirements 7.3
 */
class MigrationDownTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Таблица создаётся тестовой схемой (Tests\TestCase), а down() миграции
     * обязан её удалить.
     */
    public function test_down_drops_page_components_table(): void
    {
        $this->assertTrue(
            Schema::hasTable('page_components'),
            'Expected page_components table to exist before calling down().'
        );

        // Canonical-миграция живёт в leget-db (единственный источник схемы).
        $path = base_path('../leget-db/database/migrations/2026_04_19_000001_create_page_components_table.php');

        if (! file_exists($path)) {
            $this->markTestSkipped('Репозиторий leget-db недоступен рядом с leget-api.');
        }

        // Instantiate the anonymous migration class by requiring the file.
        $migration = require $path;

        $migration->down();

        $this->assertFalse(
            Schema::hasTable('page_components'),
            'Expected page_components table to be dropped after calling down().'
        );
    }
}
