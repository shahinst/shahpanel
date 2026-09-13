<?php

// Merged into config('payment_gateways.nowpayments') by NowPaymentsServiceProvider.
return [
    'nowpayments' => [
        'live_base_url' => env('NOWPAYMENTS_LIVE_BASE_URL', 'https://api.nowpayments.io/v1'),
        'sandbox_base_url' => env('NOWPAYMENTS_SANDBOX_BASE_URL', 'https://api-sandbox.nowpayments.io/v1'),
        'timeout_seconds' => (int) env('NOWPAYMENTS_TIMEOUT', 30),
        'min_usdt' => env('NOWPAYMENTS_MIN_USDT', '1'),
        'currencies_cache_seconds' => (int) env('NOWPAYMENTS_CURRENCIES_CACHE', 3600),
    ],
];
