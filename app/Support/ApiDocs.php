<?php

namespace App\Support;

/**
 * The endpoint reference rendered on the in-panel API documentation page.
 *
 * Kept as data rather than markup so the page, and anything else that needs
 * the contract, stay in step with one source.
 */
class ApiDocs
{
    /**
     * @return list<array{
     *     key: string,
     *     title: string,
     *     intro: ?string,
     *     endpoints: list<array{
     *         method: string,
     *         path: string,
     *         summary: string,
     *         ability: ?string,
     *         role: ?string,
     *         params: list<array{name: string, type: string, required: bool, note: string}>,
     *         returns: string
     *     }>
     * }>
     */
    public static function groups(): array
    {
        return [
            [
                'key' => 'auth',
                'title' => __('api.docs_group_auth'),
                'intro' => __('api.docs_group_auth_intro'),
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/auth/login',
                        'summary' => __('api.docs_login'),
                        'ability' => null,
                        'role' => null,
                        'params' => [
                            ['name' => 'username', 'type' => 'string', 'required' => true, 'note' => __('api.docs_p_username')],
                            ['name' => 'password', 'type' => 'string', 'required' => true, 'note' => __('api.docs_p_password')],
                            ['name' => 'two_fa_code', 'type' => 'string', 'required' => false, 'note' => __('api.docs_p_two_fa')],
                            ['name' => 'device_name', 'type' => 'string', 'required' => false, 'note' => __('api.docs_p_device')],
                            ['name' => 'expires_in_days', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_expires')],
                        ],
                        'returns' => __('api.docs_r_login'),
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/auth/me',
                        'summary' => __('api.docs_me'),
                        'ability' => null,
                        'role' => null,
                        'params' => [],
                        'returns' => __('api.docs_r_me'),
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/auth/logout',
                        'summary' => __('api.docs_logout'),
                        'ability' => null,
                        'role' => null,
                        'params' => [],
                        'returns' => __('api.docs_r_logout'),
                    ],
                ],
            ],
            [
                'key' => 'catalog',
                'title' => __('api.docs_group_catalog'),
                'intro' => __('api.docs_group_catalog_intro'),
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/catalog/packages',
                        'summary' => __('api.docs_packages'),
                        'ability' => 'catalog:read',
                        'role' => null,
                        'params' => [
                            ['name' => 'seller_id', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_seller_id')],
                        ],
                        'returns' => __('api.docs_r_packages'),
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/catalog/servers',
                        'summary' => __('api.docs_servers'),
                        'ability' => 'catalog:read',
                        'role' => null,
                        'params' => [
                            ['name' => 'package_id', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_package_filter')],
                        ],
                        'returns' => __('api.docs_r_servers'),
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/catalog/price',
                        'summary' => __('api.docs_price'),
                        'ability' => 'catalog:read',
                        'role' => null,
                        'params' => [
                            ['name' => 'package_duration_id', 'type' => 'int', 'required' => true, 'note' => __('api.docs_p_duration_id')],
                            ['name' => 'data_gb', 'type' => 'float', 'required' => false, 'note' => __('api.docs_p_data_gb_price')],
                        ],
                        'returns' => __('api.docs_r_price'),
                    ],
                ],
            ],
            [
                'key' => 'accounts',
                'title' => __('api.docs_group_accounts'),
                'intro' => __('api.docs_group_accounts_intro'),
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/accounts',
                        'summary' => __('api.docs_accounts_list'),
                        'ability' => 'accounts:read',
                        'role' => null,
                        'params' => [
                            ['name' => 'status', 'type' => 'string', 'required' => false, 'note' => __('api.docs_p_status')],
                            ['name' => 'service_type', 'type' => 'string', 'required' => false, 'note' => __('api.docs_p_service_type')],
                            ['name' => 'package_id', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_package_id')],
                            ['name' => 'server_id', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_server_id')],
                            ['name' => 'seller_id', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_seller_filter')],
                            ['name' => 'search', 'type' => 'string', 'required' => false, 'note' => __('api.docs_p_search')],
                            ['name' => 'expiring_within_days', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_expiring')],
                            ['name' => 'sort', 'type' => 'string', 'required' => false, 'note' => __('api.docs_p_sort')],
                            ['name' => 'per_page', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_per_page')],
                            ['name' => 'page', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_page')],
                        ],
                        'returns' => __('api.docs_r_accounts_list'),
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/accounts/preview',
                        'summary' => __('api.docs_preview'),
                        'ability' => 'accounts:read',
                        'role' => null,
                        'params' => [
                            ['name' => 'package_id', 'type' => 'int', 'required' => true, 'note' => __('api.docs_p_package_id')],
                            ['name' => 'package_duration_id', 'type' => 'int', 'required' => true, 'note' => __('api.docs_p_duration_id')],
                            ['name' => 'data_gb', 'type' => 'float', 'required' => false, 'note' => __('api.docs_p_data_gb_elastic')],
                        ],
                        'returns' => __('api.docs_r_preview'),
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/accounts/{id|username}',
                        'summary' => __('api.docs_account_show'),
                        'ability' => 'accounts:read',
                        'role' => null,
                        'params' => [],
                        'returns' => __('api.docs_r_account_show'),
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/accounts',
                        'summary' => __('api.docs_sell'),
                        'ability' => 'accounts:create',
                        'role' => null,
                        'params' => [
                            ['name' => 'package_id', 'type' => 'int', 'required' => true, 'note' => __('api.docs_p_package_id')],
                            ['name' => 'package_duration_id', 'type' => 'int', 'required' => true, 'note' => __('api.docs_p_duration_id')],
                            ['name' => 'remote_username', 'type' => 'string', 'required' => true, 'note' => __('api.docs_p_remote_username')],
                            ['name' => 'data_gb', 'type' => 'float', 'required' => false, 'note' => __('api.docs_p_data_gb_elastic')],
                            ['name' => 'server_id', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_server_auto')],
                            ['name' => 'sanaei_client_name', 'type' => 'string', 'required' => false, 'note' => __('api.docs_p_sanaei_name')],
                            ['name' => 'client_email', 'type' => 'string', 'required' => false, 'note' => __('api.docs_p_client_email')],
                            ['name' => 'owner_seller_id', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_owner_seller')],
                        ],
                        'returns' => __('api.docs_r_sell'),
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/accounts/{id|username}/config',
                        'summary' => __('api.docs_config'),
                        'ability' => 'accounts:read',
                        'role' => null,
                        'params' => [],
                        'returns' => __('api.docs_r_config'),
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/accounts/{id|username}/usage',
                        'summary' => __('api.docs_usage'),
                        'ability' => 'accounts:read',
                        'role' => null,
                        'params' => [
                            ['name' => 'refresh', 'type' => 'bool', 'required' => false, 'note' => __('api.docs_p_refresh')],
                        ],
                        'returns' => __('api.docs_r_usage'),
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/accounts/{id|username}/renew',
                        'summary' => __('api.docs_renew'),
                        'ability' => 'accounts:renew',
                        'role' => null,
                        'params' => [
                            ['name' => 'renewal_mode', 'type' => 'string', 'required' => false, 'note' => __('api.docs_p_renewal_mode')],
                            ['name' => 'data_gb', 'type' => 'float', 'required' => false, 'note' => __('api.docs_p_data_gb_renew')],
                            ['name' => 'package_duration_id', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_duration_renew')],
                        ],
                        'returns' => __('api.docs_r_renew'),
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/accounts/{id|username}/enable',
                        'summary' => __('api.docs_enable'),
                        'ability' => 'accounts:update',
                        'role' => null,
                        'params' => [],
                        'returns' => __('api.docs_r_toggle'),
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/accounts/{id|username}/disable',
                        'summary' => __('api.docs_disable'),
                        'ability' => 'accounts:update',
                        'role' => null,
                        'params' => [],
                        'returns' => __('api.docs_r_toggle'),
                    ],
                ],
            ],
            [
                'key' => 'wallet',
                'title' => __('api.docs_group_wallet'),
                'intro' => __('api.docs_group_wallet_intro'),
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/wallet',
                        'summary' => __('api.docs_wallet'),
                        'ability' => 'wallet:read',
                        'role' => null,
                        'params' => [],
                        'returns' => __('api.docs_r_wallet'),
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/wallet/transactions',
                        'summary' => __('api.docs_transactions'),
                        'ability' => 'wallet:read',
                        'role' => null,
                        'params' => [
                            ['name' => 'type', 'type' => 'string', 'required' => false, 'note' => __('api.docs_p_txn_type')],
                            ['name' => 'from', 'type' => 'date', 'required' => false, 'note' => __('api.docs_p_from')],
                            ['name' => 'to', 'type' => 'date', 'required' => false, 'note' => __('api.docs_p_to')],
                            ['name' => 'per_page', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_per_page')],
                        ],
                        'returns' => __('api.docs_r_transactions'),
                    ],
                ],
            ],
            [
                'key' => 'resellers',
                'agent_only' => true,
                'title' => __('api.docs_group_resellers'),
                'intro' => __('api.docs_group_resellers_intro'),
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/resellers',
                        'summary' => __('api.docs_resellers'),
                        'ability' => 'resellers:read',
                        'role' => 'agent',
                        'params' => [
                            ['name' => 'search', 'type' => 'string', 'required' => false, 'note' => __('api.docs_p_reseller_search')],
                            ['name' => 'status', 'type' => 'string', 'required' => false, 'note' => __('api.docs_p_reseller_status')],
                            ['name' => 'per_page', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_per_page')],
                        ],
                        'returns' => __('api.docs_r_resellers'),
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/resellers/{id}/accounts',
                        'summary' => __('api.docs_reseller_accounts'),
                        'ability' => 'resellers:read',
                        'role' => 'agent',
                        'params' => [
                            ['name' => 'per_page', 'type' => 'int', 'required' => false, 'note' => __('api.docs_p_per_page')],
                        ],
                        'returns' => __('api.docs_r_accounts_list'),
                    ],
                ],
            ],
            [
                'key' => 'stats',
                'title' => __('api.docs_group_stats'),
                'intro' => null,
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/stats/dashboard',
                        'summary' => __('api.docs_dashboard'),
                        'ability' => 'stats:read',
                        'role' => null,
                        'params' => [],
                        'returns' => __('api.docs_r_dashboard'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Error codes a bot should branch on.
     *
     * @return list<array{status: int, code: string, meaning: string}>
     */
    public static function errors(): array
    {
        return [
            ['status' => 401, 'code' => 'unauthenticated', 'meaning' => __('api.docs_e_unauthenticated')],
            ['status' => 403, 'code' => 'ability_missing', 'meaning' => __('api.docs_e_ability')],
            ['status' => 403, 'code' => 'role_forbidden', 'meaning' => __('api.docs_e_role')],
            ['status' => 403, 'code' => 'ip_not_allowed', 'meaning' => __('api.docs_e_ip')],
            ['status' => 404, 'code' => 'not_found', 'meaning' => __('api.docs_e_not_found')],
            ['status' => 405, 'code' => 'method_not_allowed', 'meaning' => __('api.method_not_allowed')],
            ['status' => 422, 'code' => 'validation_failed', 'meaning' => __('api.docs_e_validation')],
            ['status' => 429, 'code' => 'rate_limited', 'meaning' => __('api.docs_e_rate')],
        ];
    }
}
