<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\License;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LicenseBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_logo_and_cabinet_settings_preserve_each_other(): void
    {
        config(['lighthouse.schema_cache.enable' => false]);
        Schema::create('licenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('domain');
            $table->string('name')->nullable();
            $table->string('favicon_url')->nullable();
            $table->json('header_data')->nullable();
            $table->json('footer_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $owner = User::factory()->create();
        $license = License::create([
            'user_id' => $owner->id,
            'domain' => 'tenant.example.test',
            'favicon_url' => 'https://example.test/favicon.ico',
            'header_data' => ['logoMode' => 'text', 'phone' => '+70000000000'],
            'footer_data' => ['email' => 'info@example.test'],
        ]);
        $this->actingAs($owner, 'api');

        $logoUrl = 'https://example.test/logo.png';
        $logoMutation = 'mutation ($id: ID!, $url: String) { updateLicense(id: $id, logoUrl: $url) { id } }';
        $this->postJson('/graphql', [
            'query' => $logoMutation,
            'variables' => ['id' => $license->id, 'url' => $logoUrl],
        ])->assertOk()->assertJsonMissingPath('errors')->assertJsonPath('data.updateLicense.id', $license->id);

        $this->postJson('/graphql', [
            'query' => 'mutation ($id: ID!, $patch: JSON) { updateLicense(id: $id, name: "Site", headerDataPatch: $patch) { id } }',
            'variables' => ['id' => $license->id, 'patch' => ['city' => 'Москва', 'yandexMetrika' => '123456']],
        ])->assertOk()->assertJsonMissingPath('errors');

        $license->refresh();
        $this->assertSame([
            'logoMode' => 'image',
            'phone' => '+70000000000',
            'logoUrl' => $logoUrl,
            'city' => 'Москва',
            'yandexMetrika' => '123456',
        ], $license->header_data);
        $this->assertSame(['email' => 'info@example.test', 'logoUrl' => $logoUrl], $license->footer_data);
        $this->assertSame('https://example.test/favicon.ico', $license->favicon_url);

        $this->postJson('/graphql', [
            'query' => $logoMutation,
            'variables' => ['id' => $license->id, 'url' => null],
        ])->assertOk()->assertJsonMissingPath('errors');

        $license->refresh();
        $this->assertNull($license->header_data['logoUrl']);
        $this->assertSame(['email' => 'info@example.test', 'logoUrl' => null], $license->footer_data);
        $this->assertSame('Москва', $license->header_data['city']);
        $this->assertSame('https://example.test/favicon.ico', $license->favicon_url);
    }
}
