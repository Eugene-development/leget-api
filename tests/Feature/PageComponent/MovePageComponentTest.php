<?php

namespace Tests\Feature\PageComponent;

use App\Exceptions\GraphQLException;
use App\GraphQL\Mutations\MovePageComponent;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\User;
use App\Services\TemplateService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

class MovePageComponentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private License $license;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('licenses', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('domain');
            $table->unsignedInteger('template_id');
            $table->timestamps();
        });
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('license_id', 26);
            $table->string('slug');
            $table->timestamps();
            $table->unique(['license_id', 'slug']);
        });
        // Exercise the canonical migration, never create copies in leget-api.
        (require base_path('../leget-db/database/migrations/2026_09_12_120000_add_component_order_to_pages_table.php'))->up();
        $this->owner = User::factory()->create();
        $this->license = License::create(['user_id' => $this->owner->id, 'domain' => 'move.example.test', 'template_id' => 999]);
        config(['templates' => [999 => ['pages' => [
            '/test' => [
                ['type' => 'Hero', 'defaults' => ['title' => 'Live default']],
                ['type' => 'Text', 'defaults' => ['title' => 'Text']],
                ['type' => 'CTA', 'defaults' => ['title' => 'CTA']],
            ],
            '/items/{item}' => [
                ['type' => 'Hero', 'defaults' => []],
                ['type' => 'Text', 'defaults' => []],
            ],
        ]]]]);
    }

    private function move(string $type, string $direction, string $pageId = 'slug:/test', ?User $user = null): array
    {
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn($user ?? $this->owner);
        return app(MovePageComponent::class)(null, [
            'license_id' => $this->license->id, 'page_id' => $pageId,
            'type' => $type, 'direction' => $direction,
        ], $context, $this->createMock(ResolveInfo::class));
    }

    public function test_virtual_blocks_move_both_ways_without_freezing_defaults(): void
    {
        Cache::tags(["license:{$this->license->id}"])->put('render:fixture', 'old');
        $this->assertSame(['Text', 'Hero', 'CTA'], $this->move('Text', 'up'));
        $page = Page::firstOrFail();
        $this->assertSame(['Text', 'Hero', 'CTA'], $page->component_order);
        $this->assertDatabaseCount('page_components', 0);
        $this->assertNull(Cache::tags(["license:{$this->license->id}"])->get('render:fixture'));
        config(['templates.999.pages./test' => [
            ['type' => 'Hero', 'defaults' => ['title' => 'Updated default']],
            ['type' => 'Text', 'defaults' => []],
            ['type' => 'CTA', 'defaults' => []],
            ['type' => 'NewBlock', 'defaults' => []],
        ]]);
        $merged = app(TemplateService::class)->getMergedPageComponents($this->license->id, $page);
        $this->assertSame(['Text', 'Hero', 'CTA', 'NewBlock'], $merged->pluck('type')->all());
        $this->assertSame('Updated default', $merged->firstWhere('type', 'Hero')->data['title']);
        $this->assertSame(['Hero', 'Text', 'CTA', 'NewBlock'], $this->move('Text', 'down', (string) $page->id));
    }

    public function test_content_edits_and_reset_do_not_change_saved_order(): void
    {
        $this->move('CTA', 'up');
        $page = Page::firstOrFail();
        $component = PageComponent::create([
            'page_id' => $page->id, 'license_id' => $this->license->id, 'type' => 'Text',
            'data' => ['title' => 'Owner text'], 'sort_order' => 0, 'is_active' => true,
        ]);
        $this->move('CTA', 'up', (string) $page->id);
        $this->assertSame(['title' => 'Owner text'], $component->fresh()->data);
        $component->delete();
        $merged = app(TemplateService::class)->getMergedPageComponents($this->license->id, $page->fresh());
        $this->assertSame(['CTA', 'Hero', 'Text'], $merged->pluck('type')->all());
    }

    public function test_boundary_moves_do_nothing(): void
    {
        $this->assertSame(['Hero', 'Text', 'CTA'], $this->move('Hero', 'up'));
        $this->assertSame(['Hero', 'Text', 'CTA'], $this->move('CTA', 'down'));
        $this->assertDatabaseCount('pages', 0);
    }

    public function test_dynamic_url_uses_template_definitions(): void
    {
        $this->assertSame(['Text', 'Hero'], $this->move('Text', 'up', 'slug:/items/chair'));
        $this->assertSame('/items/chair', Page::firstOrFail()->slug);
    }

    public function test_unknown_block_does_not_create_page(): void
    {
        try {
            $this->move('Unknown', 'up');
            $this->fail('Expected validation error');
        } catch (GraphQLException $error) {
            $this->assertDatabaseCount('pages', 0);
        }
    }

    public function test_other_owner_is_rejected(): void
    {
        $other = User::factory()->create();
        $this->expectException(GraphQLException::class);
        $this->move('Text', 'up', 'slug:/test', $other);
    }

    public function test_page_from_another_license_is_rejected(): void
    {
        $other = License::create(['user_id' => $this->owner->id, 'domain' => 'other.example.test', 'template_id' => 999]);
        $page = Page::create(['license_id' => $other->id, 'slug' => '/test']);
        $this->expectException(GraphQLException::class);
        $this->move('Text', 'up', (string) $page->id);
    }

    public function test_guest_is_rejected(): void
    {
        $context = $this->createMock(GraphQLContext::class);
        $context->method('user')->willReturn(null);
        $this->expectException(GraphQLException::class);
        app(MovePageComponent::class)(null, ['license_id' => $this->license->id], $context, $this->createMock(ResolveInfo::class));
    }

    public function test_invalid_direction_is_rejected(): void
    {
        $this->expectException(GraphQLException::class);
        $this->move('Text', 'left');
    }

    public function test_global_layout_cannot_be_moved(): void
    {
        $this->expectException(GraphQLException::class);
        $this->move('Footer', 'up', 'slug:__global__');
    }
}
