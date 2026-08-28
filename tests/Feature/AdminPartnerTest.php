<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Enums\Role;
use App\Models\PartnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPartnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_sees_the_queue_with_pending_first(): void
    {
        $this->application('anna@example.test', PartnerStatus::Approved);
        $this->application('boris@example.test');

        $this->actingAs($this->superadmin(), 'api')
            ->getJson('/admin/partners')
            ->assertOk()
            ->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('summary.approved', 1)
            // Очередь разбора, а не архив: непросмотренная заявка идёт первой.
            ->assertJsonPath('applications.data.0.applicant.email', 'boris@example.test');
    }

    /**
     * Одобрение — единственное штатное место, где появляется роль `partner`.
     * Статус и роль обязаны меняться вместе.
     */
    public function test_approval_grants_the_role(): void
    {
        $profile = $this->application('anna@example.test');

        $this->actingAs($this->superadmin(), 'api')
            ->postJson("/admin/partners/{$profile->id}/approve")
            ->assertOk()
            ->assertJsonPath('application.status', PartnerStatus::Approved->value)
            ->assertJsonPath('application.applicant.role', Role::Partner->value);

        $this->assertSame(Role::Partner, $profile->user->fresh()->role);
        $this->assertNotNull($profile->fresh()->reviewed_at);
    }

    public function test_rejection_keeps_the_client_role_and_stores_the_note(): void
    {
        $profile = $this->application('anna@example.test');

        $this->actingAs($this->superadmin(), 'api')
            ->postJson("/admin/partners/{$profile->id}/reject", ['note' => 'Нет реквизитов'])
            ->assertOk()
            ->assertJsonPath('application.status', PartnerStatus::Rejected->value)
            ->assertJsonPath('application.review_note', 'Нет реквизитов');

        $this->assertSame(Role::Client, $profile->user->fresh()->role);
    }

    /**
     * Отзыв одобрения снимает роль: иначе человек потерял бы статус на бумаге,
     * но сохранил Офис.
     */
    public function test_revoking_an_approval_takes_the_role_back(): void
    {
        $profile = $this->application('anna@example.test', PartnerStatus::Approved, Role::Partner);

        $this->actingAs($this->superadmin(), 'api')
            ->postJson("/admin/partners/{$profile->id}/reject", ['note' => 'Договор расторгнут'])
            ->assertOk();

        $this->assertSame(Role::Client, $profile->user->fresh()->role);
    }

    public function test_client_cannot_reach_the_queue(): void
    {
        $profile = $this->application('anna@example.test');

        $this->actingAs($profile->user, 'api')->getJson('/admin/partners')->assertForbidden();
        $this->actingAs($profile->user, 'api')
            ->postJson("/admin/partners/{$profile->id}/approve")
            ->assertForbidden();
    }

    public function test_partner_cannot_approve_anyone(): void
    {
        $profile = $this->application('anna@example.test');
        $partner = $this->user('partner@example.test', Role::Partner);

        $this->actingAs($partner, 'api')
            ->postJson("/admin/partners/{$profile->id}/approve")
            ->assertForbidden();
    }

    public function test_missing_application_is_reported(): void
    {
        $this->actingAs($this->superadmin(), 'api')
            ->postJson('/admin/partners/01JQZZZZZZZZZZZZZZZZZZZZZZ/approve')
            ->assertNotFound();
    }

    private function application(
        string $email,
        PartnerStatus $status = PartnerStatus::Pending,
        Role $role = Role::Client,
    ): PartnerProfile {
        $user = $this->user($email, $role);

        $profile = $user->partnerProfile()->create([
            'partner_type' => PartnerType::Manufacturer->value,
            'company' => 'ООО Тест',
        ]);

        if ($status !== PartnerStatus::Pending) {
            $profile->forceFill(['status' => $status])->save();
        }

        return $profile->fresh(['user']);
    }

    private function superadmin(): User
    {
        return $this->user('boss@example.test', Role::Superadmin);
    }

    private function user(string $email, Role $role = Role::Client): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => 'Тест', 'password' => bcrypt('password')],
        );

        $user->forceFill(['role' => $role])->save();

        return $user;
    }
}
