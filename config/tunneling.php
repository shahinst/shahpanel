<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Desired-state markers
    |--------------------------------------------------------------------------
    | Every router object owned by the panel carries this comment prefix.
    | Reconcile = find-by-marker, compare, repair. NEVER change after deploy.
    */

    'marker_prefix' => 'vpnl',

    /*
    | Extra substrings matched during router wipe (comment/name/interface fields).
    | Useful when legacy configs used custom markers.
    */
    'wipe' => [
        'extra_comment_needles' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TUNNEL_WIPE_EXTRA_NEEDLES', ''))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transport addressing
    |--------------------------------------------------------------------------
    | /30 per tunnel agent allocated from this supernet (collision-free via
    | ip_pool_allocations).
    */

    'transport_supernet' => env('TUNNEL_TRANSPORT_SUPERNET', '172.16.0.0/16'),

    'client_supernet' => env('TUNNEL_CLIENT_SUPERNET', '10.64.0.0/12'),

    /*
    |--------------------------------------------------------------------------
    | MTU management
    |--------------------------------------------------------------------------
    */

    'mtu' => [
        'base' => (int) env('TUNNEL_MTU_BASE', 1500),
        // Safety margin subtracted from the calculated value when no probe ran.
        'safety_margin' => (int) env('TUNNEL_MTU_SAFETY', 8),
        'probe_min' => (int) env('TUNNEL_MTU_PROBE_MIN', 1200),
        'probe_timeout_ms' => (int) env('TUNNEL_MTU_PROBE_TIMEOUT', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring & metrics (MySQL — shared hosting, no TSDB)
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        // On-router probe scheduler interval (seconds).
        'probe_interval' => (int) env('TUNNEL_PROBE_INTERVAL', 10),
        // Raw samples retention (hours) before pruning.
        'raw_retention_hours' => (int) env('TUNNEL_RAW_RETENTION_HOURS', 48),
        // Hourly rollups retention (days).
        'rollup_retention_days' => (int) env('TUNNEL_ROLLUP_RETENTION_DAYS', 60),
        // Server capacity samples retention (hours).
        'server_raw_retention_hours' => (int) env('TUNNEL_SERVER_RAW_RETENTION_HOURS', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality scoring & failover
    |--------------------------------------------------------------------------
    */

    'scoring' => [
        'weights' => [
            'latency' => (float) env('TUNNEL_SCORE_W_LATENCY', 30),
            'jitter' => (float) env('TUNNEL_SCORE_W_JITTER', 15),
            'loss' => (float) env('TUNNEL_SCORE_W_LOSS', 35),
            'throughput' => (float) env('TUNNEL_SCORE_W_THROUGHPUT', 20),
        ],
        'latency_ref_ms' => (int) env('TUNNEL_SCORE_LATENCY_REF', 300),
        'jitter_ref_ms' => (int) env('TUNNEL_SCORE_JITTER_REF', 80),
        // Throughput below this fraction of baseline counts as throttled.
        'throttle_ratio' => (float) env('TUNNEL_THROTTLE_RATIO', 0.35),
        // Consecutive throttled/down cycles before an automatic switch.
        'switch_after_failures' => (int) env('TUNNEL_SWITCH_AFTER_FAILURES', 3),
        // Anti-flap: minimum seconds between automatic switches per agent.
        'switch_cooldown_secs' => (int) env('TUNNEL_SWITCH_COOLDOWN', 300),
        // Agent considered down after this many missed probe reports.
        'down_after_missed_reports' => (int) env('TUNNEL_DOWN_AFTER_MISSED', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server capacity caps per role (alarm + placement decisions)
    |--------------------------------------------------------------------------
    */

    'capacity' => [
        'cpu_alarm_pct' => (int) env('TUNNEL_CPU_ALARM_PCT', 70),
        'conntrack_alarm_ratio' => (float) env('TUNNEL_CONNTRACK_ALARM_RATIO', 0.8),
        'default_account_cap' => [
            'wg_public' => (int) env('TUNNEL_CAP_WG_PUBLIC', 200),
            'ppp_public' => (int) env('TUNNEL_CAP_PPP_PUBLIC', 90),
            'per_location' => (int) env('TUNNEL_CAP_PER_LOCATION', 120),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram alerts (direct Bot API over HTTP, proxy-friendly)
    |--------------------------------------------------------------------------
    */

    'telegram' => [
        'enabled' => filter_var(env('TUNNEL_TELEGRAM_ENABLED', false), FILTER_VALIDATE_BOOL),
        'bot_token' => env('TUNNEL_TELEGRAM_BOT_TOKEN', ''),
        'chat_id' => env('TUNNEL_TELEGRAM_CHAT_ID', ''),
        // e.g. socks5://user:pass@host:port or http://host:port — empty = direct.
        'proxy' => env('TUNNEL_TELEGRAM_PROXY', ''),
        'timeout_seconds' => (int) env('TUNNEL_TELEGRAM_TIMEOUT', 10),
        // Minimum minutes between duplicate alerts (per alert key).
        'dedupe_minutes' => (int) env('TUNNEL_TELEGRAM_DEDUPE_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue / locking (database queue + cron on shared hosting)
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'connection' => env('TUNNEL_QUEUE_CONNECTION', 'database'),
        'name' => env('TUNNEL_QUEUE_NAME', 'tunneling'),
        'low_name' => env('TUNNEL_LOW_QUEUE_NAME', 'tunneling-low'),
        // Skip dispatching metrics/evaluate/reconcile when the queue is this deep.
        'skip_low_priority_above' => (int) env('TUNNEL_QUEUE_SKIP_LOW_ABOVE', 30),
        // Per-server cache lock so no two jobs touch one router concurrently.
        'server_lock_seconds' => (int) env('TUNNEL_SERVER_LOCK_SECONDS', 240),
        'job_timeout' => (int) env('TUNNEL_JOB_TIMEOUT', 180),
        // Force inline apply on admin buttons (skip queue). Useful for debugging.
        'sync_on_action' => filter_var(env('TUNNELING_SYNC_ON_ACTION', true), FILTER_VALIDATE_BOOL),
        // If queue worker heartbeat is older than this, admin actions run inline.
        'worker_stale_minutes' => (int) env('TUNNEL_QUEUE_WORKER_STALE_MINUTES', 10),
    ],

    'teardown' => [
        'max_seconds' => (int) env('TUNNEL_TEARDOWN_MAX_SECONDS', 300),
        'router_budget_seconds' => (int) env('TUNNEL_TEARDOWN_ROUTER_BUDGET', 120),
        // HTTP delete only removes panel; set true to wipe routers in background after.
        'wipe_routers_after_panel_delete' => filter_var(env('TUNNEL_TEARDOWN_WIPE_ROUTERS', false), FILTER_VALIDATE_BOOL),
    ],

    'metrics' => [
        'connect_timeout' => (int) env('TUNNEL_METRICS_CONNECT_TIMEOUT', 3),
        'socket_timeout' => (int) env('TUNNEL_METRICS_SOCKET_TIMEOUT', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciler
    |--------------------------------------------------------------------------
    */

    'reconcile' => [
        'interval_minutes' => (int) env('TUNNEL_RECONCILE_INTERVAL', 5),
        'auto_repair' => filter_var(env('TUNNEL_RECONCILE_AUTO_REPAIR', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | DPI evasion
    |--------------------------------------------------------------------------
    */

    'dpi' => [
        // Default UDP port candidates for port hopping (comma list overridable per group).
        'port_candidates' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TUNNEL_PORT_CANDIDATES', '1701,4500,13231,33434,4789,51820'))
        ))),
        // l2tpv3-ip does not traverse NAT — only enable on clean public IPs.
        'l2tpv3_ip_enabled' => filter_var(env('TUNNEL_L2TPV3_IP_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Router base performance config (applied with base-config button)
    |--------------------------------------------------------------------------
    */

    'router_base' => [
        'fasttrack' => filter_var(env('TUNNEL_ROUTER_FASTTRACK', true), FILTER_VALIDATE_BOOL),
        'conntrack_tcp_established_timeout' => env('TUNNEL_CONNTRACK_TCP_TIMEOUT', '2h'),
        'conntrack_udp_timeout' => env('TUNNEL_CONNTRACK_UDP_TIMEOUT', '30s'),
        'logging_memory_only' => filter_var(env('TUNNEL_ROUTER_LOG_MEMORY_ONLY', true), FILTER_VALIDATE_BOOL),
    ],

];
