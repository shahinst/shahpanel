<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Installation
    |--------------------------------------------------------------------------
    */

    'installed_lock' => base_path('.installed.lock'),

    'install_recovery_token' => env('INSTALL_RECOVERY_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Business defaults
    |--------------------------------------------------------------------------
    */

    'currency' => env('VPN_CURRENCY', 'IRT'),

    'currency_label' => env('VPN_CURRENCY_LABEL', 'تومان'),

    'default_timezone' => env('APP_TIMEZONE', 'Asia/Tehran'),

    /*
    |--------------------------------------------------------------------------
    | Wallets
    |--------------------------------------------------------------------------
    */

    'admin_wallet_infinite' => true,

    /*
    |--------------------------------------------------------------------------
    | Client portal
    |--------------------------------------------------------------------------
    */

    'portal_token_length' => 32,

    'portal_link_ttl_minutes' => (int) env('PORTAL_LINK_TTL_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Traffic sync
    |--------------------------------------------------------------------------
    */

    'sync_interval_minutes' => (int) env('VPN_SYNC_INTERVAL', 5),

    'sync_api_timeout_seconds' => (int) env('VPN_API_TIMEOUT', 15),

    'sync_api_retry_attempts' => (int) env('VPN_API_RETRY', 3),

    /*
    |--------------------------------------------------------------------------
    | External APIs
    |--------------------------------------------------------------------------
    */

    'mikrotik' => [
        'default_port' => (int) env('MIKROTIK_DEFAULT_PORT', 8728),
        'ssl_port' => (int) env('MIKROTIK_SSL_PORT', 8729),
        // Pooled RouterOS client timeouts (seconds): connect vs per-operation read.
        'connect_timeout' => (int) env('MIKROTIK_CONNECT_TIMEOUT', 12),
        'op_timeout' => (int) env('MIKROTIK_OP_TIMEOUT', 30),
        // RouterOS-API stream timeout exceptions (PHP 8.4 can false-positive).
        'throw_timeout_exception' => filter_var(
            env('MIKROTIK_THROW_TIMEOUT_EXCEPTION', PHP_VERSION_ID < 80400 ? 'true' : 'false'),
            FILTER_VALIDATE_BOOL
        ),
        // Per-request time limit for long synchronous router operations.
        'inline_max_seconds' => (int) env('MIKROTIK_INLINE_MAX_SECONDS', 600),
        // SSH/SFTP port for downloading native .backup files (API port is usually 8728).
        'ssh_port' => (int) env('MIKROTIK_SSH_PORT', 22),
        'backup_save_timeout' => (int) env('MIKROTIK_BACKUP_SAVE_TIMEOUT', 180),
        'backup_scp_timeout' => (int) env('MIKROTIK_BACKUP_SFTP_TIMEOUT', 180),
        // Cache MikroTik reachability probes used by server auto-selection.
        'reachability_cache_seconds' => (int) env('MIKROTIK_REACHABILITY_CACHE', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | WireGuard client config defaults (account .conf generation)
    |--------------------------------------------------------------------------
    */

    'wireguard' => [
        'dns' => env('WG_CLIENT_DNS', '1.1.1.1'),
        'allowed_ips' => env('WG_CLIENT_ALLOWED_IPS', '0.0.0.0/0, ::/0'),
        'mtu' => (int) env('WG_CLIENT_MTU', 1380),
        'persistent_keepalive' => (int) env('WG_CLIENT_KEEPALIVE', 10),
        // Default subnet for account peer addresses when the server has none.
        'default_subnet' => env('WG_DEFAULT_SUBNET', '10.10.0.0/24'),
        // Max peers per WG interface; new accounts use the next interface when reached.
        'max_peers_per_interface' => (int) env('WG_MAX_PEERS_PER_INTERFACE', 250),
        // NAT + mangle rules when a WG interface is created via the panel.
        'auto_firewall_rules' => filter_var(env('WG_AUTO_FIREWALL_RULES', true), FILTER_VALIDATE_BOOL),
        'mangle_routing_marks' => [
            'low' => env('WG_MANGLE_ROUTING_MARK_LOW', 'FILTER-2>128'),
            'high' => env('WG_MANGLE_ROUTING_MARK_HIGH', 'FILTER-129>254'),
        ],
        // Parent simple queue on WG interface (target = interface name).
        'parent_queue_limit' => env('WG_PARENT_QUEUE_LIMIT', '500M/500M'),
    ],

    'sanaei' => [
        'default_port' => (int) env('SANAEI_DEFAULT_PORT', 2053),
        'api_prefix' => env('SANAEI_API_PREFIX', '/panel/api'),
        'api_prefixes' => [
            '/panel/api',
            '/xui/API',
            '/xui/api',
        ],
        'connect_timeout_seconds' => (int) env('SANAEI_CONNECT_TIMEOUT', 5),
        // Panel admin credentials travel on every login POST; only disable
        // verification for a server with a self-signed certificate.
        'verify_ssl' => filter_var(env('SANAEI_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
        // Hard ceiling for the URL/prefix fallback fan-out on one mutation.
        'max_post_attempts' => (int) env('SANAEI_MAX_POST_ATTEMPTS', 12),
        'operation_deadline_seconds' => (int) env('SANAEI_OPERATION_DEADLINE', 45),
    ],

    'pasarguard' => [
        'default_port' => (int) env('PASARGUARD_DEFAULT_PORT', 443),
        'api_prefix' => env('PASARGUARD_API_PREFIX', '/api'),
        'timeout_seconds' => (int) env('PASARGUARD_TIMEOUT', 45),
        'connect_timeout_seconds' => (int) env('PASARGUARD_CONNECT_TIMEOUT', 25),
        'test_timeout_seconds' => (int) env('PASARGUARD_TEST_TIMEOUT', 60),
        'test_connect_timeout_seconds' => (int) env('PASARGUARD_TEST_CONNECT_TIMEOUT', 30),
        'verify_ssl' => filter_var(env('PASARGUARD_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
        'users_page_size' => (int) env('PASARGUARD_USERS_PAGE_SIZE', 200),
    ],

    'cisco_anyconnect' => [
        'default_port' => (int) env('CISCO_ANYCONNECT_DEFAULT_PORT', 443),
        'timeout_seconds' => (int) env('CISCO_ANYCONNECT_TIMEOUT', 45),
        // ASA privilege-15 credentials and the user's VPN password travel on
        // every provisioning call, so verification defaults to on. Opt out per
        // server (servers.cisco_verify_ssl) for a self-signed ASA certificate.
        'verify_ssl' => filter_var(env('CISCO_ANYCONNECT_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
        // `write memory` on a busy ASA routinely outlasts a normal API call.
        'write_memory_timeout_seconds' => (int) env('CISCO_ANYCONNECT_WRITE_MEMORY_TIMEOUT', 180),
    ],

    'ocserv' => [
        // The JSON management API that runs alongside ocserv, not the VPN port itself
        // (clients still connect on 443).
        'default_port' => (int) env('OCSERV_DEFAULT_PORT', 9443),
        'timeout_seconds' => (int) env('OCSERV_TIMEOUT', 30),
        'connect_timeout_seconds' => (int) env('OCSERV_CONNECT_TIMEOUT', 10),
        // The API token and the user's VPN password travel on every call, so
        // verification defaults to on. Opt out per server (servers.ocserv_verify_ssl)
        // for a self-signed certificate.
        'verify_ssl' => filter_var(env('OCSERV_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
        'default_max_sessions' => (int) env('OCSERV_DEFAULT_MAX_SESSIONS', 1),
        // Credentials are read out to customers over the phone, so they are built to be
        // dictated: VPL847291 / 847291ab.
        'username_prefix' => env('OCSERV_USERNAME_PREFIX', 'VPL'),
        'username_digits' => (int) env('OCSERV_USERNAME_DIGITS', 6),
        'password_digits' => (int) env('OCSERV_PASSWORD_DIGITS', 6),
        'password_letters' => (int) env('OCSERV_PASSWORD_LETTERS', 2),
    ],

    'remnawave' => [
        'default_port' => (int) env('REMNAWAVE_DEFAULT_PORT', 443),
        'api_prefix' => env('REMNAWAVE_API_PREFIX', '/api'),
        'timeout_seconds' => (int) env('REMNAWAVE_TIMEOUT', 45),
        'connect_timeout_seconds' => (int) env('REMNAWAVE_CONNECT_TIMEOUT', 25),
        'test_timeout_seconds' => (int) env('REMNAWAVE_TEST_TIMEOUT', 60),
        'test_connect_timeout_seconds' => (int) env('REMNAWAVE_TEST_CONNECT_TIMEOUT', 30),
        'verify_ssl' => filter_var(env('REMNAWAVE_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
        'users_page_size' => (int) env('REMNAWAVE_USERS_PAGE_SIZE', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin dashboard — live server resource monitor
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Impersonation (ورود به پنل نماینده / فروشنده / خریدار)
    |--------------------------------------------------------------------------
    */

    'impersonation_ttl_minutes' => (int) env('IMPERSONATION_TTL_MINUTES', 3),

    'server_monitor' => [
        'cache_ttl_seconds' => (int) env('SERVER_MONITOR_CACHE_TTL', 60),
        'refresh_per_request' => (int) env('SERVER_MONITOR_REFRESH_PER_REQUEST', 2),
        'poll_interval_seconds' => (int) env('SERVER_MONITOR_POLL_INTERVAL', 30),
        'fetch_timeout_seconds' => (int) env('SERVER_MONITOR_FETCH_TIMEOUT', 12),
        'mikrotik_connect_timeout' => (int) env('SERVER_MONITOR_MIKROTIK_CONNECT', 3),
        'mikrotik_socket_timeout' => (int) env('SERVER_MONITOR_MIKROTIK_SOCKET', 5),
        'sanaei_timeout_seconds' => (int) env('SERVER_MONITOR_SANAEI_TIMEOUT', 5),
        'pasarguard_timeout_seconds' => (int) env('SERVER_MONITOR_PASARGUARD_TIMEOUT', 5),
        'remnawave_timeout_seconds' => (int) env('SERVER_MONITOR_REMNAWAVE_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    */

    'accent_color' => env('VPN_ACCENT_COLOR', '#2563eb'),

    'font_family' => 'Vazirmatn',

    /*
    |--------------------------------------------------------------------------
    | Portal URL paths (overridable in admin security settings)
    |--------------------------------------------------------------------------
    */

    'portal_paths' => [
        'admin' => env('VPN_ADMIN_PATH', 'admin'),
        'agent' => env('VPN_AGENT_PATH', 'agent'),
        'seller' => env('VPN_SELLER_PATH', 'seller'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Login CAPTCHA (session + HMAC, Persian digit display)
    |--------------------------------------------------------------------------
    */

    'login_captcha' => [
        'min_length' => (int) env('LOGIN_CAPTCHA_MIN_LENGTH', 4),
        'max_length' => (int) env('LOGIN_CAPTCHA_MAX_LENGTH', 6),
        'ttl_minutes' => (int) env('LOGIN_CAPTCHA_TTL_MINUTES', 10),
        'max_failures' => (int) env('LOGIN_CAPTCHA_MAX_FAILURES', 15),
        'fail_decay_seconds' => (int) env('LOGIN_CAPTCHA_FAIL_DECAY', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote server backups (read-only API snapshots)
    |--------------------------------------------------------------------------
    */

    'server_backup' => [
        'timeout_seconds' => (int) env('SERVER_BACKUP_TIMEOUT', 300),
        'retention_days' => (int) env('SERVER_BACKUP_RETENTION_DAYS', 30),
    ],

];
