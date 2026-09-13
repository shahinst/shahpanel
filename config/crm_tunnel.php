<?php

return [
    'comment_prefix' => env('CRM_TUN_COMMENT_PREFIX', 'CRM-TUN'),
    'subnet_pool' => env('CRM_TUN_SUBNET_POOL', '10.16.0.0/16'),
    'api_timeout' => (int) env('CRM_TUN_API_TIMEOUT', 10),
    'queue' => env('CRM_TUN_QUEUE', 'default'),
];
