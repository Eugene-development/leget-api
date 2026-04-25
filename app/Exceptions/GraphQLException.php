<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;

/**
 * Base exception for domain-specific GraphQL errors.
 *
 * Produces structured error responses with custom extension codes:
 * {
 *   "errors": [{
 *     "message": "Human-readable error description",
 *     "extensions": {
 *       "code": "ERROR_CODE",
 *       "category": "custom"
 *     }
 *   }]
 * }
 */
class GraphQLException extends Exception implements ClientAware, ProvidesExtensions
{
    protected string $errorCode;

    public function __construct(string $message, string $code = 'INTERNAL')
    {
        parent::__construct($message);
        $this->errorCode = $code;
    }

    /**
     * Mark this error as safe to expose to clients.
     */
    public function isClientSafe(): bool
    {
        return true;
    }

    /**
     * Provide custom extensions data included in the GraphQL error response.
     *
     * @return array<string, mixed>
     */
    public function getExtensions(): array
    {
        return [
            'code' => $this->errorCode,
            'category' => 'custom',
        ];
    }

    /**
     * Get the custom error code string.
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
