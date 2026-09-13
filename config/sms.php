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

];
