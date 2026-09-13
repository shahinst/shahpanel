<?php

return [
    'title' => 'امنیت و فایروال',
    'menu' => 'امنیت و فایروال',
    'section' => 'فایروال ورود',

    'ip_blocked' => 'به دلیل تلاش‌های ناموفق زیاد، دسترسی شما به صفحه ورود موقتاً بسته شده است. لطفاً :minutes دقیقه دیگر تلاش کنید.',

    'stat_active' => 'بلاک فعال',
    'stat_in_firewall' => 'در فایروال سرور',
    'stat_failures' => 'ورود ناموفق (۲۴ ساعت)',
    'stat_total' => 'کل سوابق',

    'firewall_state' => 'وضعیت فایروال',
    'fw_helper' => 'اسکریپت فایروال',
    'fw_ok' => 'فعال',
    'fw_missing' => 'در دسترس نیست',
    'fw_chain' => 'زنجیره iptables',
    'fw_hooked' => 'متصل',
    'fw_country_ranges' => 'رنج‌های مسدود کشور',
    'fw_country_note' => 'رنج آی‌پی چین، مسدود روی پورت ۸۰ و ۴۴۳',
    'fw_policy' => 'سیاست بلاک',
    'fw_policy_note' => ':attempts رمز اشتباه در :window دقیقه ⇒ :minutes دقیقه بسته‌شدن صفحه ورود. تکرار پس از بلاک ⇒ انتقال به فایروال سرور.',

    'by_country' => 'تفکیک کشور',
    'blocked_list' => 'آی‌پی‌های بلاک‌شده',
    'no_blocks' => 'موردی وجود ندارد.',
    'search_placeholder' => 'آی‌پی یا نام کاربری',
    'filter' => 'اعمال',
    'state_active' => 'فعال',
    'state_lifted' => 'رفع‌شده',
    'state_all' => 'همه',

    'col_country' => 'کشور',
    'col_ip' => 'آی‌پی',
    'col_reason' => 'علت',
    'col_username' => 'نام کاربری امتحان‌شده',
    'col_attempts' => 'تلاش',
    'col_blocked_at' => 'زمان بلاک',
    'col_expires' => 'انقضا',
    'col_state' => 'وضعیت',

    'reason_login_bruteforce' => 'تلاش ناموفق ورود',
    'reason_escalated' => 'ادامه حمله پس از بلاک',
    'reason_manual' => 'بلاک دستی ادمین',

    'in_firewall' => 'فایروال سرور',
    'login_only' => 'فقط صفحه ورود',
    'never_expires' => 'بدون انقضا',

    'unblock' => 'آنبلاک',
    'unblock_confirm' => 'این آی‌پی آنبلاک شود؟',
    'unblocked' => 'آی‌پی :ip آنبلاک شد.',
    'blocked' => 'آی‌پی :ip بلاک شد.',
    'block_refused' => 'این آی‌پی قابل بلاک نیست (نامعتبر یا در لیست سفید).',

    'manual_block' => 'بلاک دستی',
    'block_now' => 'بلاک کن',
    'duration_minutes' => 'مدت (دقیقه)',
    'duration_hint' => 'صفر یعنی بدون انقضا تا آنبلاک دستی.',

    'whitelist' => 'لیست سفید',
    'whitelist_hint' => 'این آی‌پی‌ها هرگز بلاک نمی‌شوند. آی‌پی خودت را اینجا بگذار تا اگر رمز را اشتباه زدی قفل نشوی.',
    'whitelist_empty' => 'لیست سفید خالی است.',
    'whitelist_added' => 'آی‌پی :ip به لیست سفید اضافه شد.',
    'whitelist_removed' => 'آی‌پی :ip از لیست سفید حذف شد.',
    'note' => 'توضیح',
    'add' => 'افزودن',
    'remove' => 'حذف',
    'or' => 'یا',
];
