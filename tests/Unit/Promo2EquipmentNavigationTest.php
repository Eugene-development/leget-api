<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Promo2EquipmentNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_menus_are_extended_once_without_changing_other_site_data(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->integer('template_id');
            $table->json('header_data')->nullable();
            $table->json('footer_data')->nullable();
            $table->softDeletes();
        });
        $header = ['phone' => 'own-phone', 'topLinks' => [['href' => '/custom', 'label' => 'Своя ссылка']]];
        $footer = ['siteName' => 'Своя компания', 'navColumns' => [['title' => 'Каталог', 'links' => [['href' => '/kitchens', 'label' => 'Кухни']]]]];
        foreach ([['id' => 'promo2', 'template_id' => 2], ['id' => 'other', 'template_id' => 1], ['id' => 'deleted', 'template_id' => 2, 'deleted_at' => now()]] as $row) {
            DB::table('licenses')->insert($row + ['header_data' => json_encode($header), 'footer_data' => json_encode($footer)]);
        }
        DB::table('licenses')->insert(['id' => 'defaults', 'template_id' => 2]);
        DB::table('licenses')->insert(['id' => 'empty', 'template_id' => 2, 'header_data' => '{"topLinks":[]}', 'footer_data' => '{"navColumns":[]}']);
        $migration = require base_path('../leget-db/database/migrations/2026_11_13_150000_add_promo2_equipment_navigation.php');
        $migration->up();
        $first = DB::table('licenses')->orderBy('id')->get()->toJson();
        $migration->up();
        $migration->down();
        $this->assertSame($first, DB::table('licenses')->orderBy('id')->get()->toJson());
        $row = DB::table('licenses')->where('id', 'promo2')->first();
        $savedHeader = json_decode($row->header_data, true);
        $savedFooter = json_decode($row->footer_data, true);
        $this->assertSame('own-phone', $savedHeader['phone']);
        $this->assertSame($header['topLinks'][0], $savedHeader['topLinks'][0]);
        $this->assertSame('/equipment', $savedHeader['topLinks'][1]['href']);
        $this->assertSame($footer['navColumns'][0], $savedFooter['navColumns'][0]);
        $this->assertSame('Своя компания', $savedFooter['siteName']);
        $this->assertSame('/equipment', $savedFooter['navColumns'][1]['links'][0]['href']);
        foreach (['other', 'deleted'] as $id) {
            $this->assertSame($header, json_decode(DB::table('licenses')->where('id', $id)->value('header_data'), true));
        }
        $this->assertNull(DB::table('licenses')->where('id', 'defaults')->value('header_data'));
        $empty = DB::table('licenses')->where('id', 'empty')->first();
        $this->assertSame('/equipment', json_decode($empty->header_data, true)['topLinks'][0]['href']);
        $this->assertSame('/equipment', json_decode($empty->footer_data, true)['navColumns'][0]['links'][0]['href']);
    }
}
