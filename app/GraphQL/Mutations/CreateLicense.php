<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Exceptions\GraphQLException;
use App\Models\License;
use App\Models\Page;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BillingService;
use App\Services\TemplateService;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\DB;
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

        return DB::transaction(function () use ($user, $templateId, $template, $args) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $key = $args['creationKey'] ?? null;
            if ($key) {
                $existing = License::where('user_id', $user->id)->where('creation_key', $key)->first();
                if ($existing) {
                    if ((int) $existing->template_id !== $templateId) {
                        throw new GraphQLException('Ключ запроса уже использован для другого шаблона.', 'VALIDATION');
                    }

                    return $existing;
                }
            }
            Wallet::forUser($user->id);
            $startsAt = now()->addHours(72);
            $license = License::create([
                'user_id' => $user->id,
                'domain' => 'pending-'.strtolower((string) Str::ulid()).'.leget.ru',
                'template_id' => $templateId,
                'is_active' => true, 'status' => 'active',
                'daily_price' => config("waas.template_prices.{$templateId}", 0),
                'billing_started_at' => $startsAt,
                'next_billing_date' => BillingService::firstBillingDate($startsAt),
                'creation_key' => $key,
            ]);
            foreach (array_keys($template['pages'] ?? []) as $slug) {
                $page = Page::create(['license_id' => $license->id, 'slug' => $slug]);
                $this->templateService->seedDefaultComponents($page, $templateId);
            }

            return $license;
        }, 3);
    }
}
