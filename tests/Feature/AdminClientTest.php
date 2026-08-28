<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_sees_registrations_and_superadmins_are_not_clients(): void
    {
        $this->superadminUser();
        $this->user('ivan@example.test', 'Иван');

        $this->actingAs($this->superadminUser(), 'api')
            ->getJson('/admin/clients')
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonCount(1, 'clients.data')
            ->assertJsonPath('clients.data.0.email', 'ivan@example.test')
            ->assertJsonPath('clients.data.0.email_verified', false);
    }

    public function test_search_filters_by_phone(): void
    {
        $this->user('anna@example.test', 'Анна');
        $this->user('boris@example.test', 'Борис')
            ->forceFill(['phone' => '+7 999 123-45-67'])->save();

        $this->actingAs($this->superadminUser(), 'api')
            ->getJson('/admin/clients?search=999+123')
            ->assertOk()
            ->assertJsonCount(1, 'clients.data')
            ->assertJsonPath('clients.data.0.name', 'Борис');
    }

    public function test_search_filters_by_region_and_region_is_returned(): void
    {
        $this->user('anna@example.test', 'Анна')
            ->forceFill(['region' => 'Санкт-Петербург'])->save();
        $this->user('boris@example.test', 'Борис')
            ->forceFill(['region' => 'Москва и МО'])->save();

        $this->actingAs($this->superadminUser(), 'api')
            // Кириллицу в query-string кодируем явно: тестовый клиент отдаёт строку
            // как есть, и неэкранированный запрос до валидатора не доезжает.
            ->getJson('/admin/clients?search=%D0%9F%D0%B5%D1%82%D0%B5%D1%80%D0%B1%D1%83%D1%80%D0%B3')
            ->assertOk()
            ->assertJsonCount(1, 'clients.data')
            ->assertJsonPath('clients.data.0.name', 'Анна')
            ->assertJsonPath('clients.data.0.region', 'Санкт-Петербург');
    }

    public function test_non_superadmin_cannot_read_client_list(): void
    {
        $this->actingAs($this->user('client@example.test'), 'api')
            ->getJson('/admin/clients')
            ->assertForbidden();
    }

    public function test_partner_cannot_read_client_list(): void
    {
        $this->actingAs($this->user('partner@example.test', 'Партнёр', Role::Partner), 'api')
            ->getJson('/admin/clients')
            ->assertForbidden()
            // Форма ответа общая для проекта — её задаёт рендер
            // AuthorizationException в bootstrap/app.php.
            ->assertJsonPath('success', false);
    }

    public function test_partner_stays_in_the_registration_list(): void
    {
        // Страница показывает регистрации, а не только роль «Клиент»:
        // фильтр исключает админов, поэтому появление третьей роли не убирает
        // человека из списка молча.
        $this->user('partner@example.test', 'Партнёр', Role::Partner);
        $this->user('ivan@example.test', 'Иван');

        $this->actingAs($this->superadminUser(), 'api')
            ->getJson('/admin/clients')
            ->assertOk()
            ->assertJsonPath('summary.total', 2);
    }

    public function test_guest_cannot_read_client_list(): void
    {
        $this->getJson('/admin/clients')->assertUnauthorized();
    }

    private function superadminUser(): User
    {
        return $this->user('admin@example.test', 'Админ', Role::Superadmin);
    }

    private function user(string $email, string $name = 'Клиент', Role $role = Role::Client): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => bcrypt('password')],
        );

        // Роль не fillable — назначаем явно, как это делает roles:sync-admins.
        $user->forceFill(['role' => $role])->save();

        return $user;
    }
}
