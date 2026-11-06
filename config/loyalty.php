<?php

declare(strict_types=1);

return [
    'otp' => [
        'ttl_seconds' => (int) env('LOYALTY_OTP_TTL', 300),
        'max_attempts' => 5,
        'debug_code' => env('LOYALTY_OTP_DEBUG_CODE', '000000'),
    ],

    'tiers' => [
        'bronze' => 0,
        'silver' => 1_000,
        'gold' => 5_000,
        'platinum' => 20_000,
    ],

    'points' => [
        'ttl_days' => (int) env('LOYALTY_POINTS_TTL_DAYS', 365),
    ],

    'packages' => [
        'p1000' => ['points' => 1_000, 'amount' => '500.00', 'currency' => 'RUB'],
        'p5000' => ['points' => 5_000, 'amount' => '2_000.00', 'currency' => 'RUB'],
    ],

    'catalog_cache_ttl' => (int) env('LOYALTY_CATALOG_TTL', 120),
];
