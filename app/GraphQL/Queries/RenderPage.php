<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\Models\Category;
use App\Models\License;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\Rubric;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class RenderPage
{
    public function __construct(
        private \App\Services\TemplateService $templateService
    ) {}

    /**
     * Resolve a public page for the tenant site identified by the request domain.
     *
     * @param  mixed  $root
     * @param  array{slug: string}  $args
     * @return array{site: array{name: ?string, metaDescription: ?string}, page: array{slug: string, componentsData: mixed}}
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $request = $context->request();
        $slug = '/' . ltrim($args['slug'], '/');
        $domain = $request->header('X-Forwarded-Host') ?? $request->getHost();

        // Look up the license by domain
        $license = License::where('domain', $domain)->first();

        if (! $license) {
            throw new GraphQLException('Site not found', 'SITE_NOT_FOUND');
        }

        if (! $license->is_active || $license->status === 'suspended') {
            throw new GraphQLException('Site is suspended', 'SITE_SUSPENDED');
        }

        // Use normalized slug everywhere
        $cacheKey = "render:{$license->id}:{$slug}";
        $cacheTags = ["license:{$license->id}"];
        $ttl = config('waas.cache_ttl', 3600);

        // Check cache first (use tagged cache if supported, plain cache otherwise)
        $cached = null;
        try {
            $cached = Cache::tags($cacheTags)->get($cacheKey);
        } catch (\BadMethodCallException) {
            $cached = Cache::get($cacheKey);
        }

        if ($cached !== null) {
            return $cached;
        }

        // ── Resolve Page or Dynamic Route ────────────────────────────────────
        $page = Page::where('license_id', $license->id)
            ->where('slug', $slug)
            ->first();

        $category = null;
        $project = null;
        $templateSlug = $slug;

        // If page doesn't exist in DB, check for dynamic patterns
        if (! $page) {
            // Pattern: mebel/{category_slug}/{project_slug}
            if (preg_match('#^/?mebel/([^/]+)/([^/]+)$#', $slug, $matches)) {
                $categorySlug = $matches[1];
                $projectSlug = $matches[2];
                
                $category = Category::where('slug', $categorySlug)->where('is_enabled', true)->first();
                $project = \App\Models\MebelProject::where('slug', $projectSlug)
                    ->where('is_active', true)
                    ->where(function($q) use ($license) {
                        $q->whereNull('license_id')->orWhere('license_id', $license->id);
                    })
                    ->with(['images' => fn($q) => $q->where('is_active', true)->orderBy('sort_order')])
                    ->first();
                
                if ($project) {
                    $templateSlug = '/mebel/{category}/{project}';
                }
            }
            // Pattern: mebel/{category_slug}
            elseif (preg_match('#^/?mebel/([^/]+)$#', $slug, $matches)) {
                $categorySlug = $matches[1];
                $category = Category::where('slug', $categorySlug)->where('is_enabled', true)->first();
                
                if ($category) {
                    $templateSlug = '/mebel/{category}';
                }
            }

            // Try to find the "template page" record in the DB for this dynamic route
            $page = Page::where('license_id', $license->id)
                ->where('slug', $templateSlug)
                ->first();

            if (! $page) {
                $definitions = $this->templateService->getPageComponents((int) $license->template_id, $templateSlug);
                if (empty($definitions)) {
                    throw new GraphQLException('Page not found', 'PAGE_NOT_FOUND');
                }

                // Create a virtual page object as fallback
                $page = new Page([
                    'license_id' => $license->id,
                    'slug' => $slug,
                ]);
                $page->id = 0; // Ensure ID is set even if not fillable
            }
        }

        // Use TemplateService to get merged components (handles virtual components too)
        $components = $this->templateService->getMergedPageComponents($license->id, $page, $templateSlug);

        // ── Enrich components with dynamic data ──────────────────────────────
        $components = $components->map(function ($component) use ($category, $project, $slug, $license) {
            // Always enrich MebelSidebar
            if ($component->type === 'MebelSidebar') {
                $component = clone $component;
                $liveCategories = $this->getMebelCategories();
                if ($liveCategories->isNotEmpty()) {
                    $component->data = array_merge(
                        $component->data ?? [],
                        ['categories' => $liveCategories->toArray(), 'activeSlug' => $category?->slug]
                    );
                }
            }

            // Enrich project-specific components
            if ($project) {
                if ($component->type === 'MebelProjectHero') {
                    $component = clone $component;
                    $liveCategories = $this->getMebelCategories();
                    $component->data = array_merge($component->data ?? [], [
                        'project' => [
                            'id'                => $project->id,
                            'category_id'       => $project->category_id,
                            'value'             => $project->value,
                            'slug'              => $project->slug,
                            'short_description' => $project->short_description,
                            'description'       => $project->description,
                            'price'             => $project->price,
                            'old_price'         => $project->old_price,
                            'is_new'            => $project->is_new,
                            'is_featured'       => $project->is_featured,
                            'is_active'         => $project->is_active,
                            'images'            => $project->images->map(fn($img) => ['id' => $img->id, 'url' => $img->url, 'hash' => $img->hash]),
                        ],
                        'category'   => $category ? ['id' => $category->id, 'value' => $category->value, 'slug' => $category->slug] : null,
                        'categories' => $liveCategories->toArray(),
                    ]);
                }

                if ($component->type === 'MebelProjectDescription') {
                    $component = clone $component;
                    $component->data = array_merge($component->data ?? [], [
                        'description' => $project->description,
                    ]);
                }

                if ($component->type === 'MebelProjectSimilar') {
                    $component = clone $component;
                    // Fetch related projects in the same category
                    $related = \App\Models\MebelProject::where('category_id', $project->category_id)
                        ->where('id', '!=', $project->id)
                        ->where('is_active', true)
                        ->where(function($q) use ($license) {
                            $q->whereNull('license_id')->orWhere('license_id', $license->id);
                        })
                        ->orderBy('id', 'desc')
                        ->limit(3)
                        ->with(['images' => fn($q) => $q->where('is_active', true)->orderBy('sort_order')])
                        ->get();

                    $component->data = array_merge($component->data ?? [], [
                        'projects' => $related->map(fn($p) => [
                            'id' => $p->id,
                            'value' => $p->value,
                            'slug' => $p->slug,
                            'price' => $p->price,
                            'old_price' => $p->old_price,
                            'is_new' => $p->is_new,
                            'images' => $p->images->map(fn($img) => ['url' => $img->url, 'hash' => $img->hash]),
                        ]),
                        'categorySlug' => $category?->slug,
                    ]);
                }
                
                if ($component->type === 'MebelCTA') {
                    $component = clone $component;
                    $component->data = array_merge($component->data ?? [], [
                        'projectName' => $project->value,
                    ]);
                }
            }

            // Enrich category-specific components
            if ($category && !$project) {
                if ($component->type === 'MebelCategoryHero') {
                    $component = clone $component;
                    $component->data = array_merge([
                        'title' => $category->value,
                        'description' => $category->description,
                        'categorySlug' => $category->slug,
                    ], $component->data ?? []);
                }

                if ($component->type === 'MebelProjectsGrid') {
                    $component = clone $component;
                    // Fetch projects for this category
                    $projects = \App\Models\MebelProject::where('category_id', $category->id)
                        ->where('is_active', true)
                        ->where(function($q) use ($license) {
                            $q->whereNull('license_id')->orWhere('license_id', $license->id);
                        })
                        ->orderBy('id', 'desc')
                        ->with(['images' => fn($q) => $q->where('is_active', true)->orderBy('sort_order')])
                        ->get();

                    $component->data = array_merge($component->data ?? [], [
                        'projects' => $projects->map(fn($p) => [
                            'id' => $p->id,
                            'value' => $p->value,
                            'slug' => $p->slug,
                            'short_description' => $p->short_description,
                            'price' => $p->price,
                            'is_featured' => $p->is_featured,
                            'is_new' => $p->is_new,
                            'images' => $p->images->map(fn($img) => ['url' => $img->url, 'hash' => $img->hash]),
                        ]),
                        'categorySlug' => $category->slug,
                    ]);
                }
            }

            return $component;
        });
        // ── End enrichment ────────────────────────────────────────────────────

        $response = [
            'site' => [
                'name'            => $license->name,
                'metaDescription' => $license->meta_description,
                'templateId'      => $license->template_id,
                'header'          => $license->header_data ? ['data' => $license->header_data] : null,
                'footer'          => $license->footer_data ? ['data' => $license->footer_data] : null,
            ],
            'page' => [
                'id'             => (string) ($page->id ?: 'slug:' . ($page->slug ?? $slug)),
                'licenseId'      => (string) $license->id,
                'license_id'     => (string) $license->id,
                'slug'           => $page->slug ?? $slug,
                'componentsData' => $components->filter(fn($c) => $c->is_active)->map(fn($c) => ['type' => $c->type, 'data' => $c->data])->values()->all(),
            ],
        ];

        // Store in cache with configurable TTL (default 1 hour)
        if ($ttl > 0) {
            try {
                Cache::tags($cacheTags)->put($cacheKey, $response, $ttl);
            } catch (\BadMethodCallException) {
                Cache::put($cacheKey, $response, $ttl);
            }
        }

        return $response;
    }

    /**
     * Fetch active categories for the «mebel» rubric from the database.
     *
     * Returns a Collection of arrays with keys: id, value, slug, sort_order.
     * Falls back to an empty collection if the rubric doesn't exist yet.
     *
     * @return \Illuminate\Support\Collection<int, array{id: string, value: string, slug: string, sort_order: int}>
     */
    private function getMebelCategories(): \Illuminate\Support\Collection
    {
        $rubric = Rubric::where('slug', 'mebel')
            ->where('is_active', true)
            ->first();

        if (! $rubric) {
            return collect();
        }

        return Category::where('rubric_id', $rubric->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Category $cat) => [
                'id'         => $cat->id,
                'value'      => $cat->value,
                'slug'       => $cat->slug,
                'is_enabled' => $cat->is_enabled,
                'sort_order' => $cat->sort_order,
            ]);
    }
}
