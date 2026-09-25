<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Exceptions\GraphQLException;
use App\Models\CatalogBrand;
use App\Models\Category;
use App\Models\Component;
use App\Models\License;
use App\Models\MebelProject;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\Rubric;
use App\Services\ApplianceBrands;
use App\Services\CatalogVisibility;
use App\Services\SiteSearch;
use App\Services\TemplateService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class RenderPage
{
    /**
     * Bump when the cached public response contract changes. Keeping the
     * version in the key prevents old arrays from violating new non-null
     * GraphQL fields after a zero-downtime deploy.
     */
    private const CACHE_VERSION = 'v16';

    public function __construct(
        private TemplateService $templateService,
        private CatalogVisibility $catalogVisibility
    ) {}

    /**
     * Resolve a public page for the tenant site identified by the request domain.
     *
     * @param  mixed  $root
     * @param  array{slug: string}  $args
     * @return array{site: array{name: ?string, metaDescription: ?string, ownerId: ?string}, page: array{slug: string, componentsData: mixed}}
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $request = $context->request();
        $slug = '/'.ltrim($args['slug'], '/');
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
        $cacheKey = 'render:'.self::CACHE_VERSION.":{$license->id}:{$slug}"
            .$this->catalogVisibility->cacheSuffix($license);
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
        $brand = null;
        $material = null;
        $shop = null;
        $templateSlug = $slug;

        // Check directory visibility even for an explicitly saved concrete URL.
        // Otherwise creating a Page would bypass the dynamic route's gate.
        $segments = explode('/', trim($slug, '/'));
        $rubricSlug = $segments[0] ?? '';
        if (count($segments) >= 2 && in_array($rubricSlug, array_column(CatalogVisibility::SIDEBARS, 0), true)) {
            $entry = isset(ApplianceBrands::RUBRICS[$rubricSlug])
                ? app(ApplianceBrands::class)->entries($license, $rubricSlug)->firstWhere('slug', $segments[1])
                : Category::where('slug', $segments[1])
                    ->where('is_active', true)
                    ->whereHas('rubric', fn ($q) => $q->where('slug', $rubricSlug)->where('is_active', true))
                    ->first();
            if (! $entry || ! $this->catalogVisibility->enabled($entry, $license)) {
                throw new GraphQLException('Page not found', 'PAGE_NOT_FOUND');
            }
            if ($rubricSlug === 'mebel') {
                $category = $entry;
            } elseif (isset(ApplianceBrands::RUBRICS[$rubricSlug])) {
                $brand = $entry;
            } elseif ($rubricSlug === 'stoleshnica') {
                $material = $entry;
                if (count($segments) === 3) {
                    $brand = CatalogBrand::where('category_id', $material->id)
                        ->where('slug', $segments[2])->where('is_active', true)->first();
                    if (! $brand) {
                        throw new GraphQLException('Page not found', 'PAGE_NOT_FOUND');
                    }
                } elseif (count($segments) !== 2) {
                    throw new GraphQLException('Page not found', 'PAGE_NOT_FOUND');
                }
                $templateSlug = $brand ? '/stoleshnica/{material}/{brand}' : '/stoleshnica/{material}';
            } elseif ($rubricSlug === 'furnitura') {
                if (count($segments) !== 2) {
                    throw new GraphQLException('Page not found', 'PAGE_NOT_FOUND');
                }
                $shop = $entry;
            }
        }

        // Resolve dynamic data even after saving order/SEO for a concrete URL.
        // Pattern: mebel/{category_slug}/{project_slug}
        if (preg_match('#^/?mebel/([^/]+)/([^/]+)$#', $slug, $matches)) {
            $projectSlug = $matches[2];

            $project = MebelProject::where('slug', $projectSlug)
                ->where('category_id', $category->id)
                ->where('is_active', true)
                ->where(function ($q) use ($license) {
                    $q->whereNull('license_id')->orWhere('license_id', $license->id);
                })
                ->with(['tags', 'images' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
                ->first();

            if ($project) {
                $templateSlug = '/mebel/{category}/{project}';
            }
        }
        // Pattern: mebel/{category_slug}
        elseif (preg_match('#^/?mebel/([^/]+)$#', $slug, $matches)) {
            if ($category) {
                $templateSlug = '/mebel/{category}';
            }
        }
        // Pattern: bytovaya-tehnika/{brand_slug}
        //
        // Бренд — строка того же справочника, что категории мебели, поэтому
        // рубрику проверяем явно: без этого /bytovaya-tehnika/kuhni отдал бы
        // страницу бренда по категории мебели — slug в таблице уникален
        // на все рубрики сразу.
        elseif (preg_match('#^/?bytovaya-tehnika/([^/]+)$#', $slug, $matches)) {
            if ($brand) {
                $templateSlug = '/bytovaya-tehnika/{brand}';
            }
        }
        // Pattern: santehnika/{brand_slug} — устроен так же, как бренд
        // бытовой техники: та же таблица, различает рубрика.
        elseif (preg_match('#^/?santehnika/([^/]+)$#', $slug, $matches)) {
            if ($brand) {
                $templateSlug = '/santehnika/{brand}';
            }
        }
        elseif (preg_match('#^/?osveshchenie/([^/]+)$#', $slug, $matches)) {
            if ($brand) {
                $templateSlug = '/osveshchenie/{brand}';
            }
        }
        elseif (preg_match('#^/?furnitura/([^/]+)$#', $slug, $matches)) {
            if ($shop) {
                $templateSlug = '/furnitura/{shop}';
            }
        }

        if (! $page) {
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
        $catalogItems = [];
        $components = $components->map(function ($component) use ($category, $project, $brand, $material, $shop, $license, &$catalogItems) {
            // Brand cards and the sidebar share one ordered directory + site overrides.
            $catalogType = match ($component->type) {
                'ByttehnikaBrands' => 'ByttehnikaSidebar',
                'SantehnikaBrands' => 'SantehnikaSidebar',
                'OsveshchenieBrands' => 'OsveshchenieSidebar',
                'FurnituraShops' => 'FurnituraSidebar',
                'StoleshnicaBrands' => 'StoleshnicaSidebar',
                default => $component->type,
            };
            if (isset(CatalogVisibility::SIDEBARS[$catalogType])) {
                [$rubricSlug, $itemsKey] = CatalogVisibility::SIDEBARS[$catalogType];
                $catalogItems[$rubricSlug] ??= $this->getRubricCategories($rubricSlug, $license)->toArray();
                $component = clone $component;
                $component->data = array_merge($component->data ?? [], [
                    $itemsKey => $catalogItems[$rubricSlug],
                    'activeSlug' => $material?->slug ?? $category?->slug ?? $brand?->slug ?? $shop?->slug,
                    'activeBrandSlug' => $material ? $brand?->slug : null,
                ]);
                if ($component->type === 'StoleshnicaBrands') {
                    $component->data = array_merge($component->data, [
                        'brands' => collect($catalogItems[$rubricSlug])
                            ->filter(fn ($item) => ! $material || $item['id'] === $material->id)
                            ->flatMap(fn ($item) => $item['brands'])
                            ->reject(fn ($item) => $brand instanceof CatalogBrand && $item['id'] === $brand->id)
                            ->values()->all(),
                    ]);
                }
            }

            if ($material && $component->type === 'StoleshnicaBrandHero') {
                $component = clone $component;
                // Only virtual/default fields come from the directory. Preserve
                // the owner's inline edits, including an intentionally empty description.
                $saved = $component->exists ? ($component->data ?? []) : [];
                $component->data = array_merge($component->data ?? [], array_filter([
                    'title' => $brand?->value ?? $material->value,
                    'description' => $brand?->description ?? $material->description,
                ], fn ($value, $key) => ! array_key_exists($key, $saved) && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH), [
                    'materialTitle' => $material->value,
                    'materialSlug' => $material->slug,
                    'brandSlug' => $brand?->slug,
                ]);
            }

            if ($brand instanceof CatalogBrand && $component->type === 'BrandAbout') {
                $component = clone $component;
                $component->data = array_merge($component->data ?? [], [
                    'description' => $brand->full_description ?: $brand->description ?: '',
                    'managedBrand' => true,
                ]);
            }

            if ($shop && $component->type === 'FurnituraShopHero') {
                $component = clone $component;
                $component->data = array_merge($component->data ?? [], [
                    'title' => $shop->value,
                    'logo' => $shop->logo,
                ]);
            }

            if ($shop && $component->type === 'BrandAbout') {
                $component = clone $component;
                $component->data = array_merge($component->data ?? [], [
                    'description' => $shop->full_description ?: $shop->description ?: '',
                    'managedBrand' => true,
                ]);
            }

            // Шапка страницы бренда: название и описание берём из справочника,
            // как MebelCategoryHero берёт их из своей категории. Пустое описание
            // в справочнике не затирает текст из defaults — иначе страница
            // осталась бы с одним заголовком.
            //
            // Ветка одна на обе рубрики: у техники и сантехники бренд — строка
            // одной и той же таблицы, различает их только рубрика, поэтому
            // и обогащение шапки у них общее.
            if ($brand && in_array($component->type, ['ByttehnikaBrandHero', 'SantehnikaBrandHero', 'OsveshchenieBrandHero'], true)) {
                $component = clone $component;
                $component->data = array_merge($component->data ?? [], array_filter([
                    'title' => $brand->value,
                    'description' => $brand->hero_description ?? $brand->description,
                ], fn ($value) => $value !== null && $value !== ''), [
                    'brandSlug' => $brand->slug,
                ]);
            }

            if ($brand && in_array($component->type, ['ByttehnikaBrandHero', 'SantehnikaBrandHero', 'OsveshchenieBrandHero'], true)) {
                $component = clone $component;
                $extra = ['tags' => $brand->tags->map(fn ($tag) => $tag->only(['id', 'name', 'tag_group_id']))];
                if ($brand->has_brand_content) {
                    $extra['logo'] = $brand->logo;
                    // A site-only brand has no separate short lead: its full text belongs
                    // to BrandAbout and must not be duplicated in the hero. Shared brands
                    // keep the concise directory description in hero_description.
                    if (blank($brand->hero_description ?? null)) {
                        $extra['description'] = '';
                    }
                    $extra['managedBrand'] = true;
                }
                $component->data = array_merge($component->data ?? [], $extra);
            }
            if ($brand && $brand->has_brand_content && $component->type === 'BrandAbout') {
                $component = clone $component;
                $component->data = array_merge($component->data ?? [], ['description' => $brand->description, 'managedBrand' => true]);
            }

            // Enrich project-specific components
            if ($project) {
                if ($component->type === 'MebelProjectHero') {
                    $component = clone $component;
                    $liveCategories = $this->getRubricCategories('mebel', $license);
                    $component->data = array_merge($component->data ?? [], [
                        'project' => [
                            'id' => $project->id,
                            'category_id' => $project->category_id,
                            'can_delete' => $project->license_id !== null,
                            'value' => $project->value,
                            'slug' => $project->slug,
                            'short_description' => $project->short_description,
                            'description' => $project->description,
                            // Паспорт сданной работы. Отдаётся СЫРЫМ, в отличие
                            // от ленты `/projects`: там адрес усечён до города и
                            // района для публикации, здесь он нужен целиком —
                            // это поле формы, а не текст страницы.
                            'completed_at' => $project->completed_at?->format('Y-m-d'),
                            'object_address' => $project->object_address,
                            'meta' => $project->meta ?? [],
                            'tags' => app(\App\Services\BrandTags::class)->present($project->tags, $license),
                            'price' => $project->price,
                            'old_price' => $project->old_price,
                            'is_new' => $project->is_new,
                            'is_featured' => $project->is_featured,
                            'is_active' => $project->is_active,
                            'images' => $project->images->map(fn ($img) => ['id' => $img->id, 'url' => $img->url, 'hash' => $img->hash]),
                        ],
                        'category' => $category ? ['id' => $category->id, 'value' => $category->value, 'slug' => $category->slug] : null,
                        'categories' => $liveCategories->toArray(),
                    ]);
                }

                if ($component->type === 'MebelProjectModel') {
                    $component = clone $component;
                    // Always use the current catalog model, never a snapshot saved with the block's copy.
                    $component->data = array_merge($component->data ?? [], [
                        'project' => ['id' => $project->id, 'value' => $project->value, 'meta' => $project->meta ?? []],
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
                    $related = MebelProject::where('category_id', $project->category_id)
                        ->where('id', '!=', $project->id)
                        ->where('is_active', true)
                        ->where(function ($q) use ($license) {
                            $q->whereNull('license_id')->orWhere('license_id', $license->id);
                        })
                        ->orderBy('id', 'desc')
                        ->limit(3)
                        ->with(['images' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
                        ->get();

                    $component->data = array_merge($component->data ?? [], [
                        'projects' => $related->map(fn ($p) => [
                            'id' => $p->id,
                            'value' => $p->value,
                            'slug' => $p->slug,
                            'price' => $p->price,
                            'old_price' => $p->old_price,
                            'is_new' => $p->is_new,
                            'images' => $p->images->map(fn ($img) => ['url' => $img->url, 'hash' => $img->hash]),
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
            if ($category && ! $project) {
                if ($component->type === 'MebelCategoryHero') {
                    $component = clone $component;
                    $component->data = array_merge($component->data ?? [], [
                        'title' => $category->value,
                        'description' => $category->description,
                        'categorySlug' => $category->slug,
                    ]);
                }

                if ($component->type === 'MebelProjectsGrid') {
                    $component = clone $component;
                    // Fetch projects for this category
                    $projects = MebelProject::where('category_id', $category->id)
                        ->where('is_active', true)
                        ->where(function ($q) use ($license) {
                            $q->whereNull('license_id')->orWhere('license_id', $license->id);
                        })
                        ->orderBy('id', 'desc')
                        ->with(['images' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
                        ->get();

                    $component->data = array_merge($component->data ?? [], [
                        'projects' => $projects->map(fn ($p) => [
                            'id' => $p->id,
                            'value' => $p->value,
                            'slug' => $p->slug,
                            'short_description' => $p->short_description,
                            'price' => $p->price,
                            'is_featured' => $p->is_featured,
                            'is_new' => $p->is_new,
                            'images' => $p->images->map(fn ($img) => ['url' => $img->url, 'hash' => $img->hash]),
                        ]),
                        'categorySlug' => $category->slug,
                    ]);
                }
            }

            // Страница `/projects`. Список проектов делится на ДВА блока, и
            // делится он здесь, а не на фронте: порядок живёт в запросе, и два
            // компонента, каждый по-своему берущий «первый» элемент, разошлись
            // бы при первой же правке сортировки.
            //
            //   1.26.1 ProjectsHero — последняя работа, ею открывается страница;
            //   1.26.2 ProjectsFeed — все остальные, списком с пагинацией.
            //
            // `total` получают оба: шапке он нужен как счётчик всего портфолио,
            // ленте — чтобы отличить «проектов нет вовсе» от «все показаны
            // шапкой». Во втором случае лента не рисует ни списка, ни плашки
            // «Проектов пока нет» — та была бы прямой ложью.
            // `/favorites` получает весь список: посетитель фильтрует его по
            // своим ID в браузере, включая работу из шапки `/projects`.
            if (in_array($component->type, ['ProjectsHero', 'ProjectsFeed', 'FavoritesPage'], true)) {
                $projects = $this->getFeedProjects($license);
                $component = clone $component;

                $projectData = match ($component->type) {
                    'ProjectsHero' => ['latest' => $projects[0] ?? null, 'total' => count($projects)],
                    'ProjectsFeed' => ['projects' => array_slice($projects, 1), 'total' => count($projects)],
                    'FavoritesPage' => ['projects' => $projects, 'total' => count($projects)],
                };
                $component->data = array_merge($component->data ?? [], $projectData);
            }

            return $component;
        });
        // ── End enrichment ────────────────────────────────────────────────────

        // Глобальные компоненты (футер) — общие для всех страниц сайта; хранятся на
        // зарезервированной странице '__global__'. Дописываем их после компонентов
        // текущей страницы, чтобы футер получил реальный id/`_componentId` в componentsData.
        $components = $components->concat($this->templateService->getGlobalComponents($license->id));
        $seo = $this->resolveSeo($license, $page, $templateSlug, $category ?? $material, $project, $brand ?? $shop);

        // Каталожные метаданные едут с первым ответом, отдельно от контента тенанта.
        // Eager load исключает запрос на каждый блок. Для динамических страниц
        // координатой каталога служит шаблонный slug, а не URL бренда/проекта.
        $catalog = collect();
        if ($license->template_id !== null && $components->isNotEmpty()) {
            $catalog = Component::with(['page', 'variants'])
                ->where('template_id', $license->template_id)
                ->whereHas('page', fn ($q) => $q->where('slug', $templateSlug))
                ->whereIn('type', $components->pluck('type'))
                ->get()
                ->mapWithKeys(fn (Component $component) => [$component->type => [
                    'article' => $component->article,
                    'variants' => $component->variants->map(fn ($variant) => [
                        'version' => (int) $variant->version,
                        'article' => $variant->article,
                    ])->values()->all(),
                ]]);
        }

        $response = [
            'site' => [
                'name' => $license->name,
                'metaDescription' => $license->meta_description,
                'templateId' => $license->template_id,
                'faviconUrl' => $license->favicon_url,
                // Владелец лицензии. Ответ кэшируется одним куском на всех
                // посетителей сайта, поэтому здесь не может быть признака
                // «текущий пользователь — владелец»: отдаём id, сверку делает
                // клиент. Значение и так публично (виден только сам факт «у
                // сайта есть владелец №N»), приватных данных не раскрывает.
                'ownerId' => $license->user_id === null ? null : (string) $license->user_id,
                'header' => $license->header_data ? ['data' => $license->header_data] : null,
                'footer' => $license->footer_data ? ['data' => $license->footer_data] : null,
                // Акции — данные ОДНОЙ страницы, но нужны на каждой: полоса акций
                // стоит в шапке над баннером. Поэтому список едет в `site`, рядом
                // с шапкой и подвалом, а не в `page`.
                'actionCards' => $this->getActionCards($license),
            ],
            'page' => [
                'id' => (string) ($page->id ?: 'slug:'.($page->slug ?? $slug)),
                'licenseId' => (string) $license->id,
                'license_id' => (string) $license->id,
                'slug' => $page->slug ?? $slug,
                'requestedSlug' => $slug,
                'componentsData' => $components->filter(fn ($c) => $c->is_active)->map(fn ($c) => [
                    'id' => $c->exists ? (string) $c->id : null,
                    'type' => $c->type,
                    'catalog' => $catalog->get($c->type),
                    'data' => array_merge($c->data ?? [], $c->exists ? ['_componentId' => (string) $c->id] : []),
                ])->values()->all(),
                'seo' => $seo,
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
     * Карточки акций страницы `/actions` для полосы акций (layout/PromoStrip).
     *
     * Отдаём ТОЛЬКО сохранённое тенантом и только те поля, которые полосе нужны:
     * `icon` — SVG-путь, `description` — абзац текста, и восемь таких карточек
     * ехали бы в ответ на каждой странице сайта ради двух строк в полосе.
     *
     * Три разных ответа, и различать их обязательно (разбор — в actionCards.ts):
     *   null — блок не сохранён (страница новая, тенант её не правил): фронт
     *          показывает те же дефолты, что рисует сама страница;
     *   []   — блок выключен целиком или сохранён пустым: акций нет, полоса пуста;
     *   спис. — сохранённые карточки, `enabled` у каждой.
     *
     * Своего запроса это не стоит на горячем пути: ответ renderPage кэшируется
     * целиком, а любая правка блока сбрасывает кэш по тегу лицензии.
     *
     * @return array<int, array{id: ?string, title: ?string, badge: ?string, enabled: bool}>|null
     */
    private function getActionCards(License $license): ?array
    {
        $page = Page::where('license_id', $license->id)
            ->where('slug', '/actions')
            ->first();

        if (! $page) {
            return null;
        }

        $component = PageComponent::where('page_id', $page->id)
            ->where('type', 'ActionsCards')
            ->first();

        if (! $component) {
            return null;
        }

        if (! $component->is_active) {
            return [];
        }

        $cards = $component->data['cards'] ?? null;

        if (! is_array($cards)) {
            return null;
        }

        return array_values(array_map(
            fn (array $card): array => [
                'id' => isset($card['id']) ? (string) $card['id'] : null,
                'title' => isset($card['title']) ? (string) $card['title'] : null,
                'badge' => isset($card['badge']) ? (string) $card['badge'] : null,
                // Ключа нет — карточка активна: так же читает его страница акций.
                'enabled' => ($card['enabled'] ?? true) !== false,
            ],
            array_filter($cards, 'is_array')
        ));
    }

    /**
     * Активные категории рубрики — справочник, из которого живут списки
     * сайдбаров каталога: у мебели это категории («Кухни», «Шкафы»),
     * у бытовой техники — бренды (например, «Bosch»). Разница между ними
     * только в рубрике, поэтому и запрос один.
     *
     * `is_enabled` едет вместе со списком, а не фильтрует его: владелец сайта
     * в режиме правки должен видеть выключенный пункт, чтобы вернуть его
     * тумблером. Прячет выключенное фронт (CatalogSidebar).
     *
     * Пустая коллекция означает пустой каталог, а не возврат к статике.
     *
     * @return Collection<int, array{id: string, value: string, slug: string, is_enabled: bool, sort_order: int}>
     */
    private function getRubricCategories(string $rubricSlug, License $license): Collection
    {
        if (isset(ApplianceBrands::RUBRICS[$rubricSlug])) {
            return app(ApplianceBrands::class)->entries($license, $rubricSlug)->map(fn (Category $brand) => [
                ...$brand->only(['id', 'value', 'slug', 'logo', 'description', 'sort_order']),
                'is_enabled' => $this->catalogVisibility->enabled($brand, $license),
                'tags' => $brand->tags->map(fn ($tag) => $tag->only(['id', 'name', 'tag_group_id']))->all(),
            ]);
        }
        $rubric = Rubric::where('slug', $rubricSlug)
            ->where('is_active', true)
            ->first();

        if (! $rubric) {
            return collect();
        }

        return Category::where('rubric_id', $rubric->id)
            ->where('is_active', true)
            ->when($rubricSlug === 'stoleshnica', fn ($query) => $query->with([
                'brands' => fn ($brands) => $brands->where('is_active', true),
            ]))
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Category $cat) => [
                'id' => $cat->id,
                'value' => $cat->value,
                'slug' => $cat->slug,
                'is_enabled' => $this->catalogVisibility->enabled($cat, $license),
                'sort_order' => $cat->sort_order,
                ...($rubricSlug === 'furnitura' ? [
                    'description' => $cat->description,
                    'logo' => $cat->logo,
                ] : []),
                ...($rubricSlug === 'stoleshnica' ? ['brands' => $cat->brands->map(fn (CatalogBrand $brand) => [
                    'id' => $brand->id,
                    'value' => $brand->value,
                    'slug' => $brand->slug,
                    'logo' => $brand->logo,
                    'materialTitle' => $cat->value,
                    'materialSlug' => $cat->slug,
                    'href' => '/stoleshnica/'.$cat->slug.'/'.$brand->slug,
                    'is_enabled' => $this->catalogVisibility->enabled($cat, $license),
                ])->all()] : []),
            ]);
    }

    /**
     * Проекты страницы `/projects` — все категории рубрики «Мебель» одним
     * списком, от последних созданных к ранним.
     *
     * Один запрос на оба блока страницы: первый элемент уходит в шапку
     * (`ProjectsHero`), остальные — в ленту. Делить здесь, а не двумя
     * запросами: иначе «последняя работа» и «всё, кроме последней» считались
     * бы независимо и однажды разъехались бы на границе.
     *
     * Отдаётся ЦЕЛИКОМ, без серверной пагинации: страница листает уже
     * присланное. Так сделано не из лени, а потому что ответ RenderPage
     * кэшируется одним куском на страницу (см. $cacheKey) — постраничная
     * выдача завела бы по ключу кэша на каждую страницу пагинации и на каждый
     * размер страницы, а число работ у мебельщика измеряется десятками.
     * Дойдёт до сотен — здесь появится limit и отдельный запрос за страницей.
     *
     * Порядок — по дате СОЗДАНИЯ карточки, а не по дате сдачи объекта.
     * `completed_at` в фильтр не входит намеренно: проект здесь — это карточка
     * каталога (у неё есть цена, признак новинки, хит), то есть уже готовая
     * работа, а дата сдачи — необязательный атрибут её паспорта. Сделать её
     * условием показа значило бы прятать всё, что тенант завёл до появления
     * поля, — а это весь его каталог. Карточка рисует строку «Сдан» только
     * когда дата заполнена.
     *
     * Адрес отдаётся УСЕЧЁННЫМ: `object_address` хранит его целиком для учёта,
     * а публикуется город и район. Усечение на выдаче, а не на вводе, — тенант
     * вводит один раз то, что знает, и не может случайно опубликовать больше,
     * чем собирался.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getFeedProjects(License $license): array
    {
        $rubric = Rubric::where('slug', 'mebel')->where('is_active', true)->first();

        if (! $rubric) {
            return [];
        }

        // Рубрики, отключённые владельцем сайта, не дают своих работ в ленту:
        // иначе `/projects` вела бы на страницу категории, которой в каталоге
        // нет, — то есть на 404 из собственного списка.
        $categories = Category::where('rubric_id', $rubric->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Category $category) => $this->catalogVisibility->enabled($category, $license))
            ->keyBy('id');

        if ($categories->isEmpty()) {
            return [];
        }

        return MebelProject::query()
            ->whereIn('category_id', $categories->keys())
            ->where('is_active', true)
            ->where(function ($q) use ($license) {
                $q->whereNull('license_id')->orWhere('license_id', $license->id);
            })
            // Вторым ключом id, а не sort_order: у карточек, заведённых в одну
            // секунду, порядок иначе зависел бы от нумерации ВНУТРИ категории,
            // то есть соседние карточки ленты сравнивались бы по несравнимым
            // числам. ULID монотонен по времени, поэтому он и разрешает ничью.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->with(['images' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->get()
            ->map(function (MebelProject $p) use ($categories) {
                $category = $categories->get($p->category_id);
                $meta = $p->meta ?? [];

                return [
                    'id' => $p->id,
                    'value' => $p->value,
                    'slug' => $p->slug,
                    'categorySlug' => $category?->slug,
                    'categoryValue' => $category?->value,
                    'shortDescription' => $p->short_description,
                    'completedAt' => $p->completed_at?->format('Y-m-d'),
                    'objectAddress' => $this->publicAddress($p->object_address),
                    'maker' => $meta['maker'] ?? null,
                    'hardwareBrands' => array_values((array) ($meta['hardware_brands'] ?? [])),
                    'applianceBrands' => array_values((array) ($meta['appliance_brands'] ?? [])),
                    'images' => $p->images->map(fn ($img) => ['url' => $img->url, 'hash' => $img->hash]),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Публичная часть адреса: первые два звена до запятой — обычно город и
     * район или населённый пункт и улица.
     *
     * Это адрес чужого жилья. Портфолио называет его с той точностью, с какой
     * о нём вправе говорить вслух, и решать это не должен тенант вручную при
     * каждом заполнении: ошибётся один раз — опубликует номер квартиры.
     *
     * Одно звено остаётся одним звеном: «Москва» усечению не подлежит.
     */
    private function publicAddress(?string $address): ?string
    {
        $address = trim((string) $address);

        if ($address === '') {
            return null;
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(',', $address)),
            static fn (string $part) => $part !== '',
        ));

        if ($parts === []) {
            return null;
        }

        return implode(', ', array_slice($parts, 0, 2));
    }

    /**
     * Resolve the public metadata and preserve the editable source values.
     *
     * On a dynamic route (`/mebel/{category}` and `/mebel/{category}/{project}`)
     * seo_title / seo_description are templates. Static pages use them literally.
     *
     * @return array{
     *   title: ?string,
     *   description: ?string,
     *   keywords: ?string,
     *   rawTitle: ?string,
     *   rawDescription: ?string,
     *   isDynamic: bool,
     *   pattern: string,
     *   variables: array<int, array{token: string, label: string, value: string}>
     * }
     */
    private function resolveSeo(
        License $license,
        Page $page,
        string $templateSlug,
        ?Category $category,
        ?MebelProject $project,
        Category|CatalogBrand|null $brand = null
    ): array {
        $isDynamic = str_contains($templateSlug, '{');
        $variables = [
            'site' => [
                'label' => 'Название сайта',
                'value' => (string) ($license->name ?? ''),
            ],
        ];

        if ($category) {
            $variables['category'] = [
                'label' => 'Название категории',
                'value' => (string) $category->value,
            ];
            $variables['category_description'] = [
                'label' => 'Описание категории',
                'value' => (string) ($category->description ?? ''),
            ];
        }

        if ($brand) {
            $variables['brand'] = [
                'label' => 'Название бренда',
                'value' => (string) $brand->value,
            ];
            $variables['brand_description'] = [
                'label' => 'Описание бренда',
                'value' => SiteSearch::text((string) ($brand->description ?? ''), 'description'),
            ];
        }

        if ($project) {
            $variables['project'] = [
                'label' => 'Название проекта',
                'value' => (string) $project->value,
            ];
            $variables['project_short_description'] = [
                'label' => 'Краткое описание проекта',
                'value' => (string) ($project->short_description ?? ''),
            ];
            $variables['project_description'] = [
                'label' => 'Описание проекта',
                'value' => (string) ($project->description ?? ''),
            ];
        }

        $rawTitle = $page->seo_title;
        $rawDescription = $page->seo_description;
        $rawKeywords = $page->seo_keywords;
        $directoryTitle = $brand instanceof Category ? $brand->seo_title : null;
        $directoryDescription = $brand instanceof Category ? $brand->seo_description : null;
        $directoryKeywords = $brand instanceof Category ? $brand->seo_keywords : null;
        $titleSource = $rawTitle ?? ($isDynamic ? $directoryTitle : null);
        $descriptionSource = $rawDescription ?? ($isDynamic ? $directoryDescription : null);
        $keywordsSource = $rawKeywords ?? ($isDynamic ? $directoryKeywords : null);
        $title = $isDynamic
            ? $this->renderSeoTemplate($titleSource, $variables)
            : $this->normalizeSeoValue($titleSource);
        $description = $isDynamic
            ? $this->renderSeoTemplate($descriptionSource, $variables)
            : $this->normalizeSeoValue($descriptionSource);

        return [
            'title' => $title ?? $this->normalizeSeoValue($license->name),
            'description' => $description ?? $this->normalizeSeoValue($license->meta_description),
            'keywords' => $this->normalizeSeoValue($keywordsSource),
            'rawTitle' => $rawTitle,
            'rawDescription' => $rawDescription,
            'isDynamic' => $isDynamic,
            'pattern' => $templateSlug,
            'variables' => $isDynamic
                ? collect($variables)->map(fn (array $variable, string $key) => [
                    'token' => '{'.$key.'}',
                    'label' => $variable['label'],
                    'value' => $variable['value'],
                ])->values()->all()
                : [],
        ];
    }

    /** @param array<string, array{label: string, value: string}> $variables */
    private function renderSeoTemplate(?string $template, array $variables): ?string
    {
        if ($template === null) {
            return null;
        }

        $replacements = [];
        foreach ($variables as $key => $variable) {
            $replacements['{'.$key.'}'] = $variable['value'];
        }

        $rendered = strtr($template, $replacements);
        $rendered = preg_replace('/\{[^{}]+\}/u', '', $rendered) ?? $rendered;

        return $this->normalizeSeoValue($rendered);
    }

    private function normalizeSeoValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $value = preg_replace('/\s+([,.:;!?])/u', '$1', $value) ?? $value;

        return $value === '' ? null : $value;
    }
}
