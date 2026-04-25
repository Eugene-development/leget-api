<?php

/**
 * CORS configuration for Laravel's built-in HandleCors middleware.
 *
 * NOTE: The primary CORS handling is done by App\Http\Middleware\DynamicCors,
 * which dynamically reflects the request Origin back in the response.
 * This config is kept as a reference but the built-in HandleCors middleware
 * is NOT prepended — DynamicCors takes full responsibility.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'graphql', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // '*' here is intentional — actual per-request origin reflection
    // is handled by DynamicCors middleware in bootstrap/app.php
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,

];
