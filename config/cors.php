
<?php

return [

    'paths' => [
        'api/*',
    ],

    'allowed_methods' => [
        'GET',
        'POST',
        'OPTIONS',
    ],

    'allowed_origins' => array_values(array_filter(
        array_map(
            'trim',
            explode(
                ',',
                (string) env(
                    'CORS_ALLOWED_ORIGINS',
                    env('FRONTEND_URL', 'http://localhost:3000')
                )
            )
        )
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'X-Requested-With',
        'X-API-Key',
        'Idempotency-Key',
        'X-Status-Token',
    ],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,

];
