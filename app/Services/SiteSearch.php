<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\License;
use App\Models\MebelProject;
use App\Models\Page;

/** Search public rendered content; never index the editor's raw JSON wholesale. */
final class SiteSearch
{
    public function __construct(private TemplateService $templates, private CatalogVisibility $visibility) {}

    public function paths(License $license): array
    {
        return array_keys(app(PublicSitePages::class)->sources($license));
    }

    /** Batch-load content instead of rendering every URL (and its catalog) on each cold search. */
    public function documents(License $license): array
    {
        $definitions = $this->templates->getTemplate((int) $license->template_id)['pages'] ?? [];
        $pages = Page::where('license_id', $license->id)
            ->with(['pageComponents' => fn ($q) => $q->where('license_id', $license->id)])->get()->keyBy('slug');
        $documents = [];
        foreach (app(PublicSitePages::class)->sources($license) as $path => $dynamic) {
            $pattern = $this->templates->resolveTemplateSlug((int) $license->template_id, $path);
            $page = $pages->get($path) ?? $pages->get($pattern);
            $saved = $page?->pageComponents->keyBy('type') ?? collect();
            $components = [];
            $category = $dynamic['category'] ?? null;
            $brand = $dynamic['brand'] ?? null;
            $project = $dynamic['project'] ?? null;
            foreach ($definitions[$pattern] ?? [] as $definition) {
                $type = $definition['type'];
                $component = $saved->get($type);
                if ($component && ! $component->is_active) {
                    continue;
                }
                $data = $component ? $component->data : ($definition['defaults'] ?? []);
                if ($category && in_array($type, ['MebelCategoryHero', 'ByttehnikaBrandHero', 'SantehnikaBrandHero'], true)) {
                    $data = array_merge($data, array_filter(['title' => $category->value, 'description' => $category->description], fn ($v) => $v !== null && $v !== ''));
                }
                if ($category?->has_brand_content && $type === 'BrandAbout') {
                    $data['description'] = $category->description;
                    $data['tags'] = $category->tags->map(fn ($tag) => ['name' => $tag->name])->all();
                }
                if ($category && $type === 'StoleshnicaBrandHero') {
                    foreach (['title' => $brand?->value ?? $category->value, 'description' => $brand?->description ?? $category->description] as $key => $value) {
                        if ((! $component || ! array_key_exists($key, $data)) && $value !== null && $value !== '') {
                            $data[$key] = $value;
                        }
                    }
                }
                if ($project && $type === 'MebelProjectHero') {
                    $data['project'] = $project->only(['value', 'short_description', 'description']);
                }
                if ($project && $type === 'MebelProjectDescription') {
                    $data['description'] = $project->description;
                }
                if ($project && $type === 'MebelCTA') {
                    $data['projectName'] = $project->value;
                }
                $components[] = ['type' => $type, 'data' => $data];
            }
            $title = $project?->value ?? $brand?->value ?? $category?->value;
            // An unexpanded SEO template is editor configuration, not a page title.
            if (! $title && $page?->seo_title && ! str_contains($page->seo_title, '{')) {
                $title = $page->seo_title;
            }
            $documents[] = self::document($path, ['page' => ['componentsData' => $components, 'seo' => ['title' => $title]]]);
        }

        return $documents;
    }

    public static function publicPath(string $path): bool
    {
        return str_starts_with($path, '/') && ! preg_match('~[{}?#\\\\\x00-\x20]|^//~u', $path)
            && ! preg_match('~^/(?:404|favorites|cabinet|admin|_ds|site-settings|goals)(?:/|$)~', $path);
    }

    /** Only named text fields are public search material. URLs, metadata and addresses in project forms are excluded. */
    public static function text(mixed $value, string $key = ''): string
    {
        if (is_array($value)) {
            if (($value['enabled'] ?? true) === false || ($value['is_active'] ?? true) === false || ($value['visible'] ?? true) === false) {
                return '';
            }
            $parts = [];
            foreach ($value as $childKey => $child) {
                if (is_string($childKey) && (str_starts_with($childKey, '_') || in_array($childKey, ['meta', 'catalog', 'images', 'categories', 'seo', 'settings'], true))) {
                    continue;
                }
                $parts[] = self::text($child, is_string($childKey) ? $childKey : $key);
            }

            return trim(implode(' ', array_filter($parts)));
        }
        if (! is_string($value) || ! preg_match('/^(?:(?:sub)?title|heading|text|description|short_description|content|body|name|value|label|question|answer|paragraphs?|features?|benefits?|items|badge|caption|quote|author|position|phone|email|address)(?:[A-Z_].*)?$/', $key)) {
            return '';
        }
        $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $value);
        $value = preg_replace('~<[^>]+>~', ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public static function document(string $url, array $rendered): array
    {
        $parts = [];
        $heading = '';
        foreach ($rendered['page']['componentsData'] ?? [] as $component) {
            // Navigation would make every page match the same catalog and menu labels.
            if (preg_match('/Sidebar|Menu|Header|Footer|Similar/', $component['type'] ?? '')) {
                continue;
            }
            $data = $component['data'] ?? [];
            $heading = $heading ?: self::text($data['title'] ?? $data['heading'] ?? '', 'title');
            $parts[] = self::text($data);
        }
        $title = self::text($rendered['page']['seo']['title'] ?? '', 'title');
        $body = trim(implode(' ', array_filter($parts)));

        return ['url' => $url, 'title' => $title ?: ($heading ?: (mb_substr($body, 0, 90) ?: $url)), 'text' => $body];
    }

    public static function search(array $documents, string $query, int $offset = 0): array
    {
        $query = self::normalize(trim($query));
        if (mb_strlen($query) < 3 || mb_strlen($query) > 120) {
            return ['items' => [], 'total' => 0];
        }
        $terms = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        $matches = [];
        foreach ($documents as $document) {
            $haystack = self::normalize($document['title'].' '.$document['text']);
            foreach ($terms as $term) {
                if (! str_contains($haystack, $term)) {
                    continue 2;
                }
            }
            $body = $document['text'];
            $position = mb_strpos(self::normalize($body), $terms[0]);
            $start = max(0, ($position === false ? 0 : $position) - 60);
            $snippet = ($start > 0 ? '…' : '').mb_substr($body, $start, 220);
            if (mb_strlen($body) > $start + 220) {
                $snippet .= '…';
            }
            $matches[] = ['title' => $document['title'], 'url' => $document['url'], 'snippet' => $snippet,
                'score' => str_contains(self::normalize($document['title']), $query) ? 1 : 0];
        }
        usort($matches, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['url'], $b['url']));

        return ['items' => array_slice($matches, $offset, 20), 'total' => count($matches)];
    }

    private static function normalize(string $text): string
    {
        // Fold visual lookalikes for matching only; keep titles, URLs and snippets
        // unchanged. One character stays one character, preserving snippet offsets.
        return strtr(mb_strtolower($text), [
            'а' => 'a', 'с' => 'c', 'е' => 'e', 'ё' => 'e',
            'о' => 'o', 'р' => 'p', 'х' => 'x', 'у' => 'y',
        ]);
    }
}
