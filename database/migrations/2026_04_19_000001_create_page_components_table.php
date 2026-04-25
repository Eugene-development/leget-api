<?php

use App\Models\Page;
use App\Models\PageComponent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('page_components', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->char('license_id', 26);
            $table->foreign('license_id')->references('id')->on('licenses')->cascadeOnDelete();
            $table->string('type');
            $table->json('data');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['page_id', 'type']);
        });

        // Migrate existing data from pages.components_data
        if (! Schema::hasTable('pages')) {
            return;
        }

        Page::whereNotNull('components_data')->each(function (Page $page) {
            $components = $page->components_data ?? [];
            foreach ($components as $index => $component) {
                if (empty($component['type'])) {
                    Log::warning("page_components migration: skipping item #{$index} on page {$page->id} — missing 'type'");
                    continue;
                }
                PageComponent::create([
                    'page_id'    => $page->id,
                    'license_id' => $page->license_id,
                    'type'       => $component['type'],
                    'data'       => $component['data'] ?? [],
                    'is_active'  => true,
                    'sort_order' => $index,
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_components');
    }
};
