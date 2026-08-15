<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Page;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpdatePage
{
    /**
     * Update an existing page and its SEO metadata.
     *
     * @param  mixed  $root
     * @param  array{id: string|int, license_id: string, slug?: string, seo_title?: ?string, seo_description?: ?string, seo_keywords?: ?string}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): Page
    {
        $license = License::find($args['license_id']);

        if (! $license) {
            throw new GraphQLException('License not found.', 'VALIDATION');
        }

        // Verify ownership
        if ($context->user()->id !== $license->user_id) {
            throw new GraphQLException('This action is unauthorized.', 'AUTHORIZATION');
        }

        $pageId = (string) $args['id'];
        $page = str_starts_with($pageId, 'slug:')
            ? Page::firstOrCreate([
                'license_id' => $license->id,
                'slug' => substr($pageId, 5),
            ])
            : Page::where('id', $pageId)
                ->where('license_id', $license->id)
                ->first();

        if (! $page) {
            throw new GraphQLException('Page not found.', 'VALIDATION');
        }

        // Validate slug uniqueness if slug is being changed
        if (array_key_exists('slug', $args) && $args['slug'] !== $page->slug) {
            $slugExists = Page::where('license_id', $license->id)
                ->where('slug', $args['slug'])
                ->where('id', '!=', $page->id)
                ->exists();

            if ($slugExists) {
                throw new GraphQLException('A page with this slug already exists for this license.', 'VALIDATION');
            }
        }

        $updateData = [];

        if (array_key_exists('slug', $args)) {
            $updateData['slug'] = $args['slug'];
        }

        foreach (['seo_title', 'seo_description', 'seo_keywords'] as $field) {
            if (array_key_exists($field, $args)) {
                $value = is_string($args[$field]) ? trim($args[$field]) : null;
                $updateData[$field] = $value === '' ? null : $value;
            }
        }

        $effectiveSlug = (string) ($updateData['slug'] ?? $page->slug);
        if (str_contains($effectiveSlug, '{')) {
            $this->validateSeoTemplatePlaceholders($updateData['seo_title'] ?? null);
            $this->validateSeoTemplatePlaceholders($updateData['seo_description'] ?? null);
        }

        if ($updateData !== []) {
            $page->update($updateData);
        }

        // Invalidate cache for the license (tagged if supported, plain otherwise)
        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException) {
            Cache::flush();
        }

        return $page;
    }

    private function validateSeoTemplatePlaceholders(?string $template): void
    {
        if ($template === null) {
            return;
        }

        preg_match_all('/\{([^{}]+)\}/u', $template, $matches);
        $allowed = [
            'site',
            'category',
            'category_description',
            'project',
            'project_short_description',
            'project_description',
        ];

        foreach ($matches[1] as $placeholder) {
            if (! in_array($placeholder, $allowed, true)) {
                throw new GraphQLException(
                    "Unknown SEO placeholder {{$placeholder}}.",
                    'VALIDATION'
                );
            }
        }
    }
}
