<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class EnsureAdminAccess
{
    public function __construct(private readonly AdminAccess $access) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! $this->access->allows($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Недостаточно прав для доступа к конверсиям.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
