<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\University\MediaStorage;
use Database\Seeders\UniversitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UniversityAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../../../leget-db/database/migrations/2026_09_23_140000_create_university_tables.php')->up();
        (require __DIR__.'/../../../leget-db/database/migrations/2026_09_27_160000_extend_university_content.php')->up();
    }

    private function login(Role $role = Role::Superadmin): User
    {
        $user = User::create(['name' => 'Редактор', 'email' => Str::uuid().'@example.test', 'password' => 'password']);
        $user->forceFill(['role' => $role])->save();
        $this->actingAs($user, 'api');

        return $user;
    }

    private function course(): array
    {
        $seed = json_decode(file_get_contents(__DIR__.'/../../../leget-db/database/data/university.json'), true)[0];

        return $seed + ['position' => 0];
    }

    public function test_editor_requires_admin_and_preserves_attempts_across_revisions(): void
    {
        $this->login(Role::Student);
        $this->getJson('/admin/university/courses')->assertForbidden();
        $admin = $this->login();
        $data = $this->course();
        $path = '/admin/university/courses/'.$data['slug'];
        $created = $this->postJson('/admin/university/courses', $data)->assertCreated()->assertJsonPath('item.status', 'draft')->json('item');
        $published = $this->postJson($path.'/publish', ['updated_at' => $created['updated_at']])->assertOk()->json('item');
        DB::table('university_attempts')->insert(['user_id' => $admin->id, 'course_slug' => $data['slug'], 'assessment' => 'lesson-1', 'request_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'answers' => '{}', 'score' => 100, 'passed' => true, 'created_at' => now()]);
        $changed = $published;
        $changed['curriculum']['lessons'][0]['title'] = 'Другой урок';
        $this->postJson($path, $changed)->assertStatus(409);
        $copy = $this->postJson($path.'/revision', ['slug' => 'new-edition', 'updated_at' => $published['updated_at']])->assertCreated()->json('item');
        $this->postJson('/admin/university/courses/new-edition/publish', ['updated_at' => $copy['updated_at']])->assertOk();
        $this->assertDatabaseHas('university_courses', ['slug' => $data['slug'], 'status' => 'archived']);
        $this->assertDatabaseCount('university_attempts', 1);
        $this->assertDatabaseHas('university_attempts', ['course_slug' => $data['slug'], 'score' => 100]);
    }

    public function test_validation_and_publication_reject_incomplete_content_and_media(): void
    {
        $this->login();
        $data = $this->course();
        $data['curriculum']['lessons'][0]['questions'][0]['correct'] = 9;
        $this->postJson('/admin/university/courses', $data)->assertUnprocessable();
        $data = $this->course();
        $data['curriculum'] = ['lessons' => [], 'exam' => []];
        $row = $this->postJson('/admin/university/courses', $data)->assertCreated()->json('item');
        $this->postJson('/admin/university/courses/'.$row['slug'].'/publish', ['updated_at' => $row['updated_at']])->assertUnprocessable();
        $this->postJson('/admin/university/courses/'.$row['slug'].'/archive', ['updated_at' => $row['updated_at']])->assertUnprocessable();
        $this->postJson('/admin/university/interviews', ['slug' => 'talk', 'title' => 'Беседа', 'speaker' => 'Гость', 'description' => '', 'position' => 0, 'chapters' => [], 'transcript' => [], 'video_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_stale_edits_fail_and_seeder_does_not_overwrite_admin_work(): void
    {
        $this->login();
        $data = $this->course();
        $row = $this->postJson('/admin/university/courses', $data)->assertCreated()->json('item');
        $row['title'] = 'Авторское название';
        $this->postJson('/admin/university/courses/'.$row['slug'], $row)->assertOk();
        $this->postJson('/admin/university/courses/'.$row['slug'], $row)->assertStatus(409);
        require_once __DIR__.'/../../../leget-db/database/seeders/UniversitySeeder.php';
        $original = database_path();
        app()->useDatabasePath(__DIR__.'/../../../leget-db/database');
        (new UniversitySeeder)->run();
        (new UniversitySeeder)->run();
        app()->useDatabasePath($original);
        $this->assertDatabaseHas('university_courses', ['slug' => $row['slug'], 'title' => 'Авторское название']);
        $this->assertDatabaseCount('university_courses', 14);
    }

    public function test_upload_validation_and_binary_signatures(): void
    {
        $this->login(Role::Student);
        $this->postJson('/admin/university/media', [])->assertForbidden();
        $this->login();
        $this->postJson('/admin/university/media', ['filename' => 'x.html', 'mime' => 'text/html', 'size' => 100])->assertUnprocessable();
        config(['university.uploads_enabled' => true]);
        $this->postJson('/admin/university/media', ['filename' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 52428801])->assertUnprocessable();
        $storage = new MediaStorage;
        $this->assertFalse($storage->validSignature('<script>alert(1)</script>', 'video/mp4'));
        $this->assertFalse($storage->validSignature('%PDF-1.7', 'image/png'));
        $this->assertTrue($storage->validSignature('%PDF-1.7\n', 'application/pdf'));
    }
}
