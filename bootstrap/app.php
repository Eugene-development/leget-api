<?php

use App\Http\Middleware\DynamicCors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // DynamicCors must be first — it handles OPTIONS preflight and sets
        // Access-Control-Allow-Origin dynamically based on the request Origin.
        $middleware->prepend(DynamicCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
