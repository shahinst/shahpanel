<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application firewall (L7)
    |--------------------------------------------------------------------------
    */

    'firewall_enabled' => env('SECURITY_FIREWALL', true),

    'firewall_log_blocks' => env('SECURITY_FIREWALL_LOG', true),

    /*
    |--------------------------------------------------------------------------
    | Suspicious patterns scanned in request input (keys + scalar values).
    | Applied to GET/POST/COOKIE — password fields are skipped.
    |--------------------------------------------------------------------------
    */

    'firewall_patterns' => [
        '/(?i)(union\s+(all\s+)?select|insert\s+into|drop\s+(table|database)|update\s+.+\s+set\s+)/',
        '/(?i)(<script\b|javascript\s*:|onerror\s*=|onload\s*=|<iframe\b)/',
        '/(?i)(base64_decode\s*\(|eval\s*\(|shell_exec\s*\(|passthru\s*\(|system\s*\(|exec\s*\()/',
        '/(\.\.\/|\.\.\\\\|%2e%2e%2f|%2e%2e%5c)/i',
        '/(\x00|%00)/',
        '/(?i)(;\s*(shutdown|xp_cmdshell|load_file)\b)/',
    ],

    'firewall_skip_keys' => [
        'password',
        'password_confirmation',
        'current_password',
        'client_password',
        'remote_password',
        'wireguard_private_key',
        'two_factor_code',
        '_token',
        '_method',
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin panel IP whitelist (empty = allow all). Comma-separated in .env
    | or overridden in admin security settings (setting: admin_ip_whitelist).
    |--------------------------------------------------------------------------
    */

    'admin_ip_whitelist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SECURITY_ADMIN_IP_WHITELIST', ''))
    ))),

];
