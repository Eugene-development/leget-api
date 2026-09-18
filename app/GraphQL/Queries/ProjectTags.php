<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\License;
use App\Models\MebelProject;
use App\Services\BrandTags;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class ProjectTags
{
    public function __invoke(MebelProject $project, array $args, GraphQLContext $context): array
    {
        $request = $context->request();
        $license = License::where('domain', $request->header('X-Forwarded-Host') ?? $request->getHost())
            ->where('is_active', true)->where('status', '!=', 'suspended')->first();
        if ($project->license_id && $license?->id !== $project->license_id) {
            // Owner mutation responses can also be made directly to the API host.
            $license = $context->user()?->licenses()->find($project->license_id);
            if (! $license) {
                return [];
            }
        }

        return app(BrandTags::class)->present($project->tags, $license)->all();
    }
}
