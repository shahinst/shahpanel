<?php

return [

    'default_provider' => env('SMS_PROVIDER', 'sms_ir'),

    'sms_ir' => [
        'base_url' => rtrim((string) env('SMS_IR_BASE_URL', 'https://api.sms.ir/v1'), '/'),
        'timeout_seconds' => max(10, (int) env('SMS_IR_TIMEOUT_SECONDS', 30)),
        'connect_timeout_seconds' => max(5, (int) env('SMS_IR_CONNECT_TIMEOUT_SECONDS', 15)),
        'template_list_paths' => [
            'send/verify/templates',
            'send/verify/template',
            'template',
        ],
        'default_account_message' => "آدرس ورود شما :\n#LOGIN#",
        'default_verify_parameter' => 'LOGIN',
    ],

    'idehpayam' => [
        'base_url' => rtrim((string) env('IDEHPAYAM_BASE_URL', 'http://185.112.33.62/api/v1/rest'), '/'),
        'timeout_seconds' => max(10, (int) env('IDEHPAYAM_TIMEOUT_SECONDS', 30)),
        'connect_timeout_seconds' => max(5, (int) env('IDEHPAYAM_CONNECT_TIMEOUT_SECONDS', 15)),
        // 0 = normal message, 1 = flash (shown on screen, not stored).
        'types' => [0, 1],
    ],

];
