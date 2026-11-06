<?php

declare(strict_types=1);

return [
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'payments' => [
        'driver' => env('PAYMENT_DRIVER', 'fake'),
    ],

    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret_key' => env('YOOKASSA_SECRET_KEY'),
        'base_url' => env('YOOKASSA_BASE_URL', 'https://api.yookassa.ru/v3'),
        'return_url' => env('YOOKASSA_RETURN_URL', env('APP_URL', 'http://localhost')),
        'webhook_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('YOOKASSA_WEBHOOK_IPS', ''))
        ))),
    ],
];
