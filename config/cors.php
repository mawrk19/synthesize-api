<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The SPA calls this API with Bearer tokens (not cookies).
    | Set CORS_ALLOWED_ORIGINS and/or FRONTEND_URL in production.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_unique(array_map(
        static fn (string $origin): string => trim($origin),
        array_merge(
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
            array_filter([(string) env('FRONTEND_URL', '')]),
            env('APP_ENV') === 'local'
                ? ['http://localhost:5173', 'http://127.0.0.1:5173']
                : [],
        )
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
