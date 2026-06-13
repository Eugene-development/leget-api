<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Page;
use App\Services\TemplateService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpdateLicense
{
    public function __construct(private readonly TemplateService $templateService) {}

    /**
     * Update site settings for the given license.
     *
     * When template_id is set for the first time (or changed), automatically
     * creates all pages defined in the template config and seeds their default
     * components — so the site is immediately ready to view.
     *
     * @param  mixed  $root
     * @param  array{id: string, domain?: string, name?: string, meta_description?: string, template_id?: int, header_data?: mixed, footer_data?: mixed}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): License
    {
        $license = License::find($args['id']);

        if (! $license) {
            throw new GraphQLException('License not found.', 'VALIDATION');
        }

        // Verify ownership
        if ($context->user()->id !== $license->user_id) {
            throw new GraphQLException('This action is unauthorized.', 'AUTHORIZATION');
        }

        // Validate template_id if provided
        if (array_key_exists('template_id', $args) && $args['template_id'] !== null) {
            $templates = config('templates');
            if (! isset($templates[$args['template_id']])) {
                throw new GraphQLException("Template {$args['template_id']} not found.", 'VALIDATION');
            }
        }

        // Build update data from provided args
        $updateData = [];

        foreach (['domain', 'name', 'meta_description', 'template_id', 'header_data', 'footer_data', 'favicon_url'] as $field) {
            if (array_key_exists($field, $args)) {
                $updateData[$field] = $args[$field];
            }
        }

        // Resiliently support camelCase faviconUrl if Lighthouse did not rename it in resolver args
        if (array_key_exists('faviconUrl', $args)) {
            $updateData['favicon_url'] = $args['faviconUrl'];
        }

        $license->update($updateData);

        // If template_id was set or changed — seed all pages and default components
        if (array_key_exists('template_id', $args) && $args['template_id'] !== null) {
            $this->seedTemplatePages($license, $args['template_id']);

            // Set daily price and billing start if not already set
            $license->daily_price = config("waas.template_prices.{$args['template_id']}", 0);
            if (! $license->billing_started_at) {
                $license->billing_started_at = now()->addHours(72);
            }
            $license->save();
        }

        // Invalidate cache for the license (tagged if supported, plain otherwise)
        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException) {
            // database/file cache stores don't support tagging — flush all
            Cache::flush();
        }

        return $license;
    }

    /**
     * Create all pages defined in the template config (if they don't exist yet)
     * and seed their default components.
     */
    private function seedTemplatePages(License $license, int $templateId): void
    {
        $template = $this->templateService->getTemplate($templateId);

        if (! $template) {
            return;
        }

        $slugs = array_keys($template['pages'] ?? []);

        foreach ($slugs as $slug) {
            // Get or create the page (idempotent)
            $page = Page::firstOrCreate(
                ['license_id' => $license->id, 'slug' => $slug],
                ['license_id' => $license->id, 'slug' => $slug]
            );

            // Seed default components (skips types that already exist)
            $this->templateService->seedDefaultComponents($page, $templateId);
        }
    }
}
