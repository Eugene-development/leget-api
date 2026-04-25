<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pages', 'components_data')) {
            Schema::table('pages', function (Blueprint $table) {
                $table->dropColumn('components_data');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pages') && ! Schema::hasColumn('pages', 'components_data')) {
            Schema::table('pages', function (Blueprint $table) {
                $table->json('components_data')->nullable()->after('slug');
            });
        }
    }
};
