<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Conversion;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('conversions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('channel', 16);
            $table->string('type', 64);
            $table->string('name');
            $table->string('contact', 255)->nullable();
            $table->string('ad_id', 32)->nullable();
            $table->text('comment')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->char('service_request_id', 26)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function test_superadmin_can_view_conversions(): void
    {
        $admin = $this->user('admin@example.test', Role::Superadmin);
        Conversion::query()->create([
            'channel' => Conversion::CHANNEL_ONLINE,
            'type' => 'consultation',
            'name' => 'Анна',
            'contact' => '+7 999 123-45-67',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/conversions')
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.online', 1)
            ->assertJsonPath('conversions.data.0.name', 'Анна');
    }

    public function test_authenticated_non_superadmin_cannot_view_conversions(): void
    {
        $this->actingAs($this->user('member@example.test'), 'api')
            ->getJson('/admin/conversions')
            ->assertForbidden();
    }

    public function test_superadmin_can_record_normalized_offline_conversion(): void
    {
        $this->actingAs($this->user('admin@example.test', Role::Superadmin), 'api')
            ->postJson('/admin/conversions', [
                'offline_type' => 'call',
                'name' => 'ООО Пример',
                'contact' => '8 (999) 123-45-67',
                'ad_id' => '123456789',
                'comment' => 'Позвонили после рекламы',
            ])
            ->assertCreated()
            ->assertJsonPath('conversion.channel', Conversion::CHANNEL_OFFLINE)
            ->assertJsonPath('conversion.type', 'offline_call')
            ->assertJsonPath('conversion.contact', '79991234567');
    }

    private function user(string $email, Role $role = Role::Client): User
    {
        $user = User::query()->create([
            'name' => 'Test User',
            'email' => $email,
            'password' => bcrypt('password'),
        ]);

        // Роль не fillable — назначаем явно, как это делает roles:sync-admins.
        $user->forceFill(['role' => $role])->save();

        return $user;
    }
}
