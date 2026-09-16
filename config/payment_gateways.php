<?php

return [
    // NowPayments config is provided by the nowpayments module
    // (config('payment_gateways.nowpayments')) only while that module is active.
    'zarinpal' => [
        'live_base_url' => env('ZARINPAL_LIVE_BASE_URL', 'https://payment.zarinpal.com/pg/v4'),
        'sandbox_base_url' => env('ZARINPAL_SANDBOX_BASE_URL', 'https://sandbox.zarinpal.com/pg/v4'),
        'live_start_pay_url' => env('ZARINPAL_LIVE_START_PAY_URL', 'https://payment.zarinpal.com/pg/StartPay/'),
        'sandbox_start_pay_url' => env('ZARINPAL_SANDBOX_START_PAY_URL', 'https://sandbox.zarinpal.com/pg/StartPay/'),
        'timeout_seconds' => (int) env('ZARINPAL_TIMEOUT', 30),
        'min_toman' => env('ZARINPAL_MIN_TOMAN', '1000'),
    ],
    'card_to_card' => [
        'min_toman' => env('CARD_TO_CARD_MIN_TOMAN', '1000'),
    ],
];
