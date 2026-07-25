<?php

$parseOrigins = static function (string ...$values): array {
    $origins = [];

    foreach ($values as $value) {
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $origins[] = $part;
            }
        }
    }

    return $origins;
};

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

    'allowed_origins' => array_values(array_unique($parseOrigins(
        (string) env('CORS_ALLOWED_ORIGINS', ''),
        (string) env('FRONTEND_URL', ''),
        ...(env('APP_ENV') === 'local'
            ? [
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'http://localhost:5174',
                'http://127.0.0.1:5174',
                'http://localhost:5175',
                'http://127.0.0.1:5175',
            ]
            : []),
    ))),

    'allowed_origins_patterns' => env('APP_ENV') === 'local'
        ? ['#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#']
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
