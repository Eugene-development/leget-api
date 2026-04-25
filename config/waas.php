<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Render Page Cache TTL
    |--------------------------------------------------------------------------
    |
    | Duration in seconds for caching renderPage responses in Redis.
    | Set to 0 to disable caching.
    |
    */

    'cache_ttl' => (int) env('WAAS_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Upload URL TTL
    |--------------------------------------------------------------------------
    |
    | Duration in seconds for pre-signed S3 upload URLs.
    | Default: 600 (10 minutes).
    |
    */

    'upload_url_ttl' => (int) env('WAAS_UPLOAD_URL_TTL', 600),

];
