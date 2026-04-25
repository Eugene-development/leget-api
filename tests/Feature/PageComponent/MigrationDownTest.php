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
     * After RefreshDatabase runs all migrations, page_components must exist.
     * Calling down() on the migration must drop the table.
     */
    public function test_down_drops_page_components_table(): void
    {
        // RefreshDatabase has already run all migrations, so the table must exist.
        $this->assertTrue(
            Schema::hasTable('page_components'),
            'Expected page_components table to exist after migrations ran.'
        );

        // Instantiate the anonymous migration class by requiring the file.
        $migration = require base_path('database/migrations/2026_04_19_000001_create_page_components_table.php');

        $migration->down();

        $this->assertFalse(
            Schema::hasTable('page_components'),
            'Expected page_components table to be dropped after calling down().'
        );
    }
}
