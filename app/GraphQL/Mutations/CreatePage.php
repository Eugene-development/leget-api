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

final class CreatePage
{
    public function __construct(private readonly TemplateService $templateService) {}

    /**
     * Create a new page for the given license and seed default components from the template.
     *
     * @param  mixed  $root
     * @param  array{license_id: string, slug: string}  $args
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

        // Validate slug uniqueness within license scope
        $slugExists = Page::where('license_id', $license->id)
            ->where('slug', $args['slug'])
            ->exists();

        if ($slugExists) {
            throw new GraphQLException('A page with this slug already exists for this license.', 'VALIDATION');
        }

        $page = Page::create([
            'license_id' => $license->id,
            'slug'       => $args['slug'],
        ]);

        // Seed default components from the template config
        if ($license->template_id) {
            $this->templateService->seedDefaultComponents($page, $license->template_id);
        }

        // Invalidate cache for the license (tagged if supported, plain otherwise)
        try {
            Cache::tags(["license:{$license->id}"])->flush();
        } catch (\BadMethodCallException) {
            Cache::flush();
        }

        return $page;
    }
}
