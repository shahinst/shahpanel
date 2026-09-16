<?php

/*
 * Strings produced by PHP (controllers, console commands, enums, exceptions)
 * rather than by a Blade view. Keys are flat and prefixed by area.
 */
return [

    // Automation — expiring account thresholds
    'automation_days_min' => 'حداقل ۱ روز.',
    'automation_days_max' => 'حداکثر ۷ روز.',
    'automation_volume_min' => 'حداقل ۱۰۰ مگابایت.',
    'automation_volume_max' => 'حداکثر ۵۱۲۰ مگابایت (۵ گیگ).',
    'automation_expiring_saved' => 'آستانه‌ی اکانت‌های در حال انقضا ذخیره شد.',

    // Tunnel load balancing modes
    'balancing_mode_pcc' => 'PCC (بر اساس اتصال)',
    'balancing_mode_ecmp' => 'ECMP (وزن‌دار)',
    'balancing_mode_range_split' => 'تقسیم بر اساس محدوده IP (بین لوکیشن‌ها)',

    // Broadcast audiences (the "sellers" audience reuses broadcasts.audience_sellers)
    'broadcast_audience_agents' => 'نمایندگان',
    'broadcast_audience_agent_sellers' => 'فروشندگان این نماینده',

    // Cron documentation shown in the maintenance screen
    'cron_schedule_label' => 'اجرای زمان‌بند Laravel',
    'cron_schedule_description' => 'الزامی — هر دقیقه یک‌بار؛ همه jobهای زیر از این دستور اجرا می‌شوند.',
    'cron_queue_label' => 'پردازش صف (Queue Worker)',
    'cron_queue_description' => 'الزامی برای سیستم تونلینگ جدید — هر دقیقه صف database را پردازش می‌کند (apply / reconcile / متریک‌ها) و بعد از ۵۵ ثانیه یا خالی‌شدن صف خارج می‌شود.',
    'cron_sync_usage_label' => 'سینک حجم مصرفی VPN',
    'cron_sync_usage_description' => 'خواندن مصرف از سرورها → دیتابیس؛ در صورت اتمام حجم، اکانت غیرفعال می‌شود.',
    'cron_check_expiry_label' => 'بررسی انقضای اکانت‌ها',
    'cron_check_expiry_description' => 'غیرفعال‌سازی اکانت‌های منقضی بر اساس expiry_at در دیتابیس.',
    'cron_alerts_label' => 'هشدارها (ایمیل/اعلان)',
    'cron_alerts_description' => 'نزدیک شدن به انقضا، ۹۰٪ حجم، خطای sync.',
    'cron_backup_label' => 'پشتیبان‌گیری دیتابیس',
    'cron_backup_description' => 'هر شب ساعت ۰۲:۰۰.',
    'cron_daily_report_label' => 'گزارش روزانه',
    'cron_daily_report_description' => 'جمع‌بندی آمار روزانه نیمه‌شب.',
    'cron_auto_close_tickets_label' => 'بستن خودکار تیکت‌ها',
    'cron_auto_close_tickets_description' => 'روزانه.',

    // Currency names (IRT reuses packages.toman)
    'currency_try' => 'لیر ترکیه',
    'currency_usd' => 'دلار',
    'currency_eur' => 'یورو',

    // Identity verification
    'kyc_package_not_required' => 'این پکیج نیاز به احراز ندارد.',
    'kyc_status_draft' => 'در انتظار احراز',
    'kyc_status_verified' => 'احراز شده',
    'kyc_status_locked' => 'قفل (بیش از حد تلاش)',
    'kyc_status_reset_requested' => 'درخواست ریست',
    'kyc_status_used' => 'مصرف‌شده برای اکانت',

    // Modules
    'module_file_attribute' => 'فایل ماژول',
    'module_zip_only' => 'فقط فایل با پسوند zip مجاز است.',
    'module_install_failed' => 'نصب ماژول ناموفق بود: :message',
    'module_installed' => 'ماژول «:name» با موفقیت آپلود و نصب شد. برای استفاده آن را فعال کنید.',
    'module_activate_failed' => 'فعال‌سازی ماژول ناموفق بود: :message',
    'module_activated' => 'ماژول «:name» فعال شد.',
    'module_deactivated' => 'ماژول «:name» غیرفعال شد.',
    'module_deleted' => 'ماژول «:name» به‌طور کامل حذف شد.',

    // "Panel is not installed yet" page
    'not_installed_title' => 'هنوز نصب نشده',
    'not_installed_heading' => 'پنل هنوز نصب نشده است',
    'not_installed_ssh_only' => 'نصب فقط از راه SSH انجام می‌شود:',
    'not_installed_manual_hint' => 'اگر کد را دستی نصب کرده‌اید، پس از :command این دستور را اجرا کنید:',

    // Panel notifications written by scheduled commands
    'notify_account_expired_title' => 'انقضای اکانت',
    'notify_account_expired_body' => 'اکانت :username منقضی شد.',
    'notify_expiry_reminder_title' => 'یادآوری انقضا',
    'notify_expiry_reminder_body' => 'اکانت :username تا :date منقضی می‌شود.',
    'notify_quota_warning_title' => 'هشدار حجم',
    'notify_quota_warning_body' => 'اکانت :username بیش از ۹۰٪ حجم مصرف شده است.',
    'notify_server_sync_error_title' => 'خطا در همگام‌سازی سرور',
    'notify_server_sync_error_body' => 'سرور :server — :errors خطا',

    // Bulk package pricing
    'pricing_packages_required' => 'حداقل یک پکیج را انتخاب کنید.',
    'pricing_percent_required' => 'درصد را وارد کنید.',
    'pricing_no_price_changed' => 'هیچ قیمتی تغییر نکرد (پکیج‌های انتخابی قیمت معتبر نداشتند).',
    'pricing_prices_updated' => 'قیمت :packages پکیج (:durations مورد قیمت) با موفقیت به‌روزرسانی شد.',
    'pricing_no_duration_changed' => 'هیچ دوره‌ای تغییر نکرد (یا همه از قبل تنظیم بودند یا قیمت ۱ماهه نداشتند).',
    'pricing_durations_generated' => 'قیمت دوره‌ای برای :packages پکیج (:durations دوره) محاسبه، ثبت و فعال شد.',

    // Reports — KPI cards
    'report_revenue_total_admin' => 'درآمد کل (فاکتورها)',
    'report_revenue_total_agent' => 'گردش مالی مجموعه',
    'report_revenue_total_seller' => 'مجموع خرید شما',
    'report_revenue_hint' => 'جدید: :new | تمدید: :renew',
    'report_new_accounts' => 'اکانت‌های جدید',
    'report_new_accounts_hint' => ':count تمدید در این بازه',
    'report_refunds' => 'برگشت از خرید',
    'report_admin_revenue' => 'درآمد ادمین',
    'report_admin_revenue_hint' => 'سود نماینده‌ها: :amount',
    'report_new_agents' => 'نمایندگان جدید',
    'report_new_sellers' => 'فروشندگان جدید',
    'report_new_clients' => 'مشتریان جدید',
    'report_agent_profit' => 'سود شما (بازه)',
    'report_my_wallet' => 'موجودی کیف‌پول شما',

    // Agent-set seller pricing
    'reseller_pricing_not_allowed' => 'ادمین اجازه‌ی تعیین قیمت فروشنده را برای شما فعال نکرده است.',
    'reseller_pricing_saved' => 'قیمت فروشنده‌ها ذخیره شد.',

    // Accounting repair
    'seller_profit_clawback_description' => 'تصحیح سود اشتباه فروش — :marker',

    // Server connection test — detail labels
    'server_detail_panel_url' => 'آدرس پنل',
    'server_detail_api_url' => 'آدرس API',
    'server_detail_api_prefix' => 'مسیر API',
    'server_detail_admin_username' => 'کاربر پنل',
    'server_detail_panel_version' => 'نسخه پنل',
    'server_detail_inbound_count' => 'تعداد inbound',
    'server_detail_group_count' => 'تعداد گروه (ذخیره‌شده در سرور)',
    'server_detail_squad_count' => 'تعداد squad',
    'server_detail_node_count' => 'تعداد node',
    'server_detail_groups_synced_at' => 'آخرین سینک گروه‌ها',
    'server_detail_user_count' => 'تعداد کاربر (نمونه)',
    'server_detail_error' => 'خطا',

    // Server operation log lines
    'server_log_error' => 'خطا: :message',
    'server_log_status' => 'وضعیت: :status',
    'server_log_accounts_synced' => 'اکانت‌های سینک‌شده: :count',
    'server_log_errors_count' => 'خطاها: :count',
    'server_log_permissions_allowed' => 'دسترسی‌های فعال: :list',
    'server_log_permissions_denied' => 'دسترسی‌های محدود: :list',
    'server_log_tried_urls' => 'آدرس‌های امتحان‌شده: :list',
    'server_log_warnings' => 'هشدار: :list',
    'server_log_group_entry' => 'گروه: #:id — :name',
    'server_log_stored_groups' => 'گروه‌های ذخیره‌شده روی سرور: :count — :at',
    'server_log_full_log' => 'لاگ کامل: :file',

    // Wallet
    'wallet_insufficient_balance' => 'موجودی کیف پول کافی نیست. از منوی «درخواست‌های شارژ» موجودی را افزایش دهید.',

];
