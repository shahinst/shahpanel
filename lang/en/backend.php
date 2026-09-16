<?php

/*
 * Strings produced by PHP (controllers, console commands, enums, exceptions)
 * rather than by a Blade view. Keys are flat and prefixed by area.
 */
return [

    // Automation — expiring account thresholds
    'automation_days_min' => 'At least 1 day.',
    'automation_days_max' => 'At most 7 days.',
    'automation_volume_min' => 'At least 100 MB.',
    'automation_volume_max' => 'At most 5120 MB (5 GB).',
    'automation_expiring_saved' => 'The expiring-accounts threshold has been saved.',

    // Tunnel load balancing modes
    'balancing_mode_pcc' => 'PCC (per connection)',
    'balancing_mode_ecmp' => 'ECMP (weighted)',
    'balancing_mode_range_split' => 'Split by IP range (across locations)',

    // Broadcast audiences (the "sellers" audience reuses broadcasts.audience_sellers)
    'broadcast_audience_agents' => 'Agents',
    'broadcast_audience_agent_sellers' => 'Sellers of this Agent',

    // Cron documentation shown in the maintenance screen
    'cron_schedule_label' => 'Laravel scheduler run',
    'cron_schedule_description' => 'Required — once a minute; every job below runs from this command.',
    'cron_queue_label' => 'Queue processing (queue worker)',
    'cron_queue_description' => 'Required for the new tunnelling system — processes the database queue every minute (apply / reconcile / metrics) and exits after 55 seconds or once the queue is empty.',
    'cron_sync_usage_label' => 'VPN usage sync',
    'cron_sync_usage_description' => 'Reads usage from the servers into the database; an account is disabled once its data runs out.',
    'cron_check_expiry_label' => 'Account expiry check',
    'cron_check_expiry_description' => 'Disables expired accounts based on expiry_at in the database.',
    'cron_alerts_label' => 'Alerts (email/notification)',
    'cron_alerts_description' => 'Approaching expiry, 90% of data used, sync errors.',
    'cron_backup_label' => 'Database backup',
    'cron_backup_description' => 'Every night at 02:00.',
    'cron_daily_report_label' => 'Daily report',
    'cron_daily_report_description' => 'Rolls up the daily statistics at midnight.',
    'cron_auto_close_tickets_label' => 'Auto-close tickets',
    'cron_auto_close_tickets_description' => 'Daily.',

    // Currency names (IRT reuses packages.toman)
    'currency_try' => 'Turkish lira',
    'currency_usd' => 'Dollar',
    'currency_eur' => 'Euro',

    // Identity verification
    'kyc_package_not_required' => 'This package does not need identity verification.',
    'kyc_status_draft' => 'Awaiting verification',
    'kyc_status_verified' => 'Verified',
    'kyc_status_locked' => 'Locked (too many attempts)',
    'kyc_status_reset_requested' => 'Reset requested',
    'kyc_status_used' => 'Used for an account',

    // Modules
    'module_file_attribute' => 'module file',
    'module_zip_only' => 'Only files with a .zip extension are allowed.',
    'module_install_failed' => 'Installing the module failed: :message',
    'module_installed' => 'Module ":name" was uploaded and installed. Enable it to start using it.',
    'module_activate_failed' => 'Enabling the module failed: :message',
    'module_activated' => 'Module ":name" has been enabled.',
    'module_deactivated' => 'Module ":name" has been disabled.',
    'module_deleted' => 'Module ":name" has been removed completely.',

    // "Panel is not installed yet" page
    'not_installed_title' => 'Not installed yet',
    'not_installed_heading' => 'The panel is not installed yet',
    'not_installed_ssh_only' => 'Installation is done over SSH only:',
    'not_installed_manual_hint' => 'If you installed the code by hand, run this command after :command:',

    // Panel notifications written by scheduled commands
    'notify_account_expired_title' => 'Account expiry',
    'notify_account_expired_body' => 'Account :username has expired.',
    'notify_expiry_reminder_title' => 'Expiry reminder',
    'notify_expiry_reminder_body' => 'Account :username expires on :date.',
    'notify_quota_warning_title' => 'Data warning',
    'notify_quota_warning_body' => 'Account :username has used more than 90% of its data.',
    'notify_server_sync_error_title' => 'Server sync error',
    'notify_server_sync_error_body' => 'Server :server — :errors errors',

    // Bulk package pricing
    'pricing_packages_required' => 'Select at least one package.',
    'pricing_percent_required' => 'Enter a percentage.',
    'pricing_no_price_changed' => 'No price changed (the selected packages had no valid price).',
    'pricing_prices_updated' => 'Prices updated for :packages package(s) (:durations price entries).',
    'pricing_no_duration_changed' => 'No duration changed (either they were already set, or there was no 1-month price).',
    'pricing_durations_generated' => 'Duration pricing calculated, saved and enabled for :packages package(s) (:durations durations).',

    // Reports — KPI cards
    'report_revenue_total_admin' => 'Total revenue (invoices)',
    'report_revenue_total_agent' => 'Network turnover',
    'report_revenue_total_seller' => 'Your total purchases',
    'report_revenue_hint' => 'New: :new | Renewal: :renew',
    'report_new_accounts' => 'New accounts',
    'report_new_accounts_hint' => ':count renewals in this period',
    'report_refunds' => 'Refunds',
    'report_admin_revenue' => 'Admin revenue',
    'report_admin_revenue_hint' => 'Agent commission: :amount',
    'report_new_agents' => 'New Agents',
    'report_new_sellers' => 'New Sellers',
    'report_new_clients' => 'New Clients',
    'report_agent_profit' => 'Your commission (period)',
    'report_my_wallet' => 'Your wallet balance',

    // Agent-set seller pricing
    'reseller_pricing_not_allowed' => 'The Admin has not enabled Seller price setting for you.',
    'reseller_pricing_saved' => 'Seller prices have been saved.',

    // Accounting repair
    'seller_profit_clawback_description' => 'Correction of wrongly recorded sales commission — :marker',

    // Server connection test — detail labels
    'server_detail_panel_url' => 'Panel URL',
    'server_detail_api_url' => 'API URL',
    'server_detail_api_prefix' => 'API path',
    'server_detail_admin_username' => 'Panel user',
    'server_detail_panel_version' => 'Panel version',
    'server_detail_inbound_count' => 'Inbound count',
    'server_detail_group_count' => 'Group count (stored on the server)',
    'server_detail_squad_count' => 'Squad count',
    'server_detail_node_count' => 'Node count',
    'server_detail_groups_synced_at' => 'Last group sync',
    'server_detail_user_count' => 'User count (sample)',
    'server_detail_error' => 'Error',

    // Server operation log lines
    'server_log_error' => 'Error: :message',
    'server_log_status' => 'Status: :status',
    'server_log_accounts_synced' => 'Accounts synced: :count',
    'server_log_errors_count' => 'Errors: :count',
    'server_log_permissions_allowed' => 'Granted permissions: :list',
    'server_log_permissions_denied' => 'Restricted permissions: :list',
    'server_log_tried_urls' => 'URLs tried: :list',
    'server_log_warnings' => 'Warning: :list',
    'server_log_group_entry' => 'Group: #:id — :name',
    'server_log_stored_groups' => 'Groups stored on the server: :count — :at',
    'server_log_full_log' => 'Full log: :file',

    // Wallet
    'wallet_insufficient_balance' => 'Your wallet balance is not enough. Add balance from the "Top-up requests" menu.',

];
