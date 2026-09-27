<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UniversityProgramSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_preserves_custom_order_and_leaves_unrelated_pages_untouched(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->integer('template_id');
        });
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('license_id');
            $table->string('slug');
            $table->json('component_order')->nullable();
        });
        DB::table('licenses')->insert([
            ['id' => 'one', 'template_id' => 1], ['id' => 'two', 'template_id' => 2],
        ]);
        $original = ['UniversityJournal', 'UniversityHero', 'UniversityApproach'];
        $custom = ['UniversityProgram', 'UniversityHero', 'UniversityJournal'];
        DB::table('pages')->insert([
            ['id' => 1, 'license_id' => 'one', 'slug' => '/university', 'component_order' => json_encode($original)],
            ['id' => 2, 'license_id' => 'one', 'slug' => '/university', 'component_order' => null],
            ['id' => 3, 'license_id' => 'two', 'slug' => '/university', 'component_order' => json_encode($original)],
            ['id' => 4, 'license_id' => 'one', 'slug' => '/university', 'component_order' => json_encode($custom)],
            ['id' => 5, 'license_id' => 'one', 'slug' => '/other', 'component_order' => json_encode($original)],
        ]);
        $migration = require base_path('../leget-db/database/migrations/2026_09_27_000001_split_university_program_order.php');
        $migration->up();
        $migration->up();
        $this->assertSame(['UniversityJournal', 'UniversityHero', 'UniversityProgram', 'UniversityApproach'], json_decode(DB::table('pages')->where('id', 1)->value('component_order'), true));
        $this->assertNull(DB::table('pages')->where('id', 2)->value('component_order'));
        $this->assertSame($original, json_decode(DB::table('pages')->where('id', 3)->value('component_order'), true));
        $this->assertSame($custom, json_decode(DB::table('pages')->where('id', 4)->value('component_order'), true));
        $this->assertSame($original, json_decode(DB::table('pages')->where('id', 5)->value('component_order'), true));
        $this->assertSame(['UniversityHero', 'UniversityProgram', 'UniversityJournal', 'UniversityApproach'], array_column(config('templates.1.pages./university'), 'type'));
    }
}
