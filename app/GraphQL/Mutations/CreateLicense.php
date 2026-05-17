<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Page;
use App\Services\TemplateService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class CreateLicense
{
    public function __construct(private readonly TemplateService $templateService) {}

    /**
     * Create a new license for the authenticated user with the given template.
     *
     * Automatically seeds all pages and default components from the template config
     * so the site is immediately ready to view.
     *
     * @param  mixed  $root
     * @param  array{template_id: int}  $args
     *
     * @throws GraphQLException
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): License
    {
        $templateId = $args['template_id'];

        // Validate template exists
        $template = $this->templateService->getTemplate($templateId);
        if (! $template) {
            throw new GraphQLException("Template {$templateId} not found.", 'VALIDATION');
        }

        $user = $context->user();

        $dailyPrice = config("waas.template_prices.{$templateId}", 0);

        // Create license with a temporary domain (user will set real domain later)
        $license = License::create([
            'id'          => (string) Str::ulid(),
            'user_id'     => $user->id,
            'domain'      => 'pending-' . strtolower((string) Str::ulid()) . '.leget.ru',
            'template_id' => $templateId,
            'is_active'   => true,
            'status'      => 'active',
            'daily_price' => $dailyPrice,
            'billing_started_at' => now()->addHours(72),
        ]);

        // Seed all pages and default components from template config
        $slugs = array_keys($template['pages'] ?? []);
        foreach ($slugs as $slug) {
            $page = Page::create([
                'license_id' => $license->id,
                'slug'       => $slug,
            ]);
            $this->templateService->seedDefaultComponents($page, $templateId);
        }

        return $license;
    }
}
