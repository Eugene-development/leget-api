<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\ServiceRequest;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class CreateServiceRequest
{
    /**
     * Create a new service request.
     *
     * @param  mixed  $root
     * @param  array{input: array{service_type: string, name: string, phone: string, message: string|null, source_url: string|null, city: string|null}}  $args
     */
    public function __invoke($root, array $args, GraphQLContext $context, ResolveInfo $info): ServiceRequest
    {
        $input = $args['input'];

        // Automatically capture IP and User Agent on the server side
        $ipAddress = request()->ip();
        $userAgent = request()->userAgent();

        return ServiceRequest::create([
            'service_type' => $input['service_type'],
            'name'         => $input['name'],
            'phone'        => $input['phone'],
            'message'      => $input['message'] ?? null,
            'source_url'   => $input['source_url'] ?? null,
            'city'         => $input['city'] ?? null,
            'ip_address'   => $ipAddress,
            'user_agent'   => $userAgent,
            'status'       => ServiceRequest::STATUS_NEW,
        ]);
    }
}
