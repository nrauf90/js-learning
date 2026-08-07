<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Every call carries an `Authorization` header, which makes it a
    // preflighted request. At 0 the browser may not cache the OPTIONS result,
    // so each GET costs two round trips through the full framework boot rather
    // than one — the single biggest avoidable cost on a page that fires three
    // calls. 24h is the ceiling Chrome and Firefox both clamp to.
    'max_age' => (int) env('CORS_MAX_AGE', 86400),

    'supports_credentials' => true,

];
