<?php

namespace Tests\Feature\PageComponent;

use App\Exceptions\GraphQLException;
use App\GraphQL\Mutations\UpsertPageComponent;
use App\Models\License;
use App\Models\PageComponent;
use App\Models\User;
use App\Services\TemplateService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

class VacancyEditingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('licenses')) {
            Schema::create('licenses', function (Blueprint $table) {
                $table->string('id', 26)->primary();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('domain')->unique();
                $table->string('name')->nullable();
                $table->text('meta_description')->nullable();
                $table->unsignedInteger('template_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pages')) {
            Schema::create('pages', function (Blueprint $table) {
                $table->id();
                $table->string('license_id', 26);
                $table->string('slug');
                $table->timestamps();

                $table->foreign('license_id')
                    ->references('id')
                    ->on('licenses')
                    ->onDelete('cascade');

                $table->unique(['license_id', 'slug']);
            });
        }
    }

    private function ownerAndLicense(): array
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => bcrypt('password')]);
        $license = License::create(['user_id' => $owner->id, 'domain' => 'vacancies.example.test', 'template_id' => 1, 'is_active' => true, 'status' => 'active']);

        return [$owner, $license];
    }

    private function save(User $user, License $license, array $data): PageComponent
    {
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn($user);

        return app(UpsertPageComponent::class)(null, [
            'page_id' => 'slug:/vacancy', 'license_id' => $license->id,
            'type' => 'VacancyList', 'data' => $data,
        ], $context, $this->createMock(ResolveInfo::class));
    }

    public function test_owner_can_add_edit_hide_publish_and_remove_all_vacancies(): void
    {
        [$owner, $license] = $this->ownerAndLicense();
        $defaults = collect(config('templates')[1]['pages']['/vacancy'])->firstWhere('type', 'VacancyList')['defaults'];
        $new = [...$defaults['vacancies'][0], 'id' => 'new', 'title' => 'Новая должность', 'hidden' => true];
        $data = [...$defaults, 'vacancies' => [...$defaults['vacancies'], $new]];
        $row = $this->save($owner, $license, $data);
        $this->assertSame($data, $row->fresh()->data);
        $data['vacancies'][4]['title'] = 'Архитектор';
        $data['vacancies'][4]['hidden'] = false;
        $this->assertSame($data, $this->save($owner, $license, $data)->fresh()->data);
        $data['vacancies'][0]['hidden'] = true;
        $this->assertSame($data, $this->save($owner, $license, $data)->fresh()->data);
        $data['vacancies'] = [];
        $this->save($owner, $license, $data);
        $rendered = app(TemplateService::class)->getMergedPageComponents($license->id, (string) $row->page_id)->firstWhere('type', 'VacancyList');
        $this->assertSame([], $rendered->data['vacancies']);
        $this->assertSame(1, PageComponent::count());
    }

    public function test_another_owner_cannot_change_vacancies(): void
    {
        [$owner, $license] = $this->ownerAndLicense();
        $this->save($owner, $license, ['vacancies' => []]);
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => bcrypt('password')]);
        try {
            $this->save($other, $license, ['vacancies' => [['id' => 'unauthorised']]]);
            $this->fail('An unrelated user must not be able to edit vacancies');
        } catch (GraphQLException $error) {
            $this->assertSame('AUTHORIZATION', $error->getErrorCode());
            $this->assertSame(['vacancies' => []], PageComponent::first()->data);
        }
    }

    public function test_guest_cannot_save_vacancies(): void
    {
        [, $license] = $this->ownerAndLicense();
        $response = $this->postJson('/graphql', [
            'query' => 'mutation ($license: ID!, $data: JSON!) { upsertPageComponent(pageId: "slug:/vacancy", licenseId: $license, type: "VacancyList", data: $data) { id } }',
            'variables' => ['license' => $license->id, 'data' => ['vacancies' => []]],
        ]);
        $this->assertStringContainsString('Unauthenticated', json_encode($response->json('errors')));
        $this->assertSame(0, PageComponent::count());
    }
}
