<?php

return [
    'page_title' => 'پایش سرورها',
    'intro' => 'هر پنج دقیقه سرورها، فضای دیسک پنل، انقضای گواهی‌های SSL و ظرفیت IP سرورهای AnyConnect بررسی می‌شوند. هر مشکل یک بار (و تا رفع، هر شش ساعت) با ربات تلگرام بکاپ به شما خبر داده می‌شود و رفعش هم اعلام می‌شود.',
    'telegram_missing' => 'ربات تلگرام بکاپ تنظیم نشده است؛ بررسی‌ها انجام می‌شوند ولی هشداری فرستاده نمی‌شود. توکن و شناسهٔ چت را در بخش بکاپ سرورها وارد کنید.',
    'run_now' => 'بررسی همین حالا',
    'ran' => 'بررسی انجام شد.',
    'last_run' => 'آخرین بررسی: :time',
    'watchdog_disk_percent' => 'هشدار وقتی فضای خالی دیسک کمتر از (درصد)',
    'watchdog_cert_days' => 'هشدار وقتی تا انقضای گواهی SSL کمتر از (روز)',
    'watchdog_pool_percent' => 'هشدار وقتی مصرف IPهای AnyConnect بیشتر از (درصد)',
    'label_server' => 'سرور :name',
    'label_disk' => 'فضای دیسک پنل',
    'label_cert' => 'گواهی SSL :host',
    'label_pool' => 'ظرفیت IP سرور :name',
    'server_down' => 'سرور :name (:host::port) از پنل در دسترس نیست.',
    'disk_low' => 'فضای خالی دیسک پنل :free از :total (:percent٪) است.',
    'cert_expiring' => 'گواهی SSL :host تا :days روز دیگر (:date) منقضی می‌شود.',
    'pool_full' => 'سرور AnyConnect :name :used از :size IP بازه را مصرف کرده (:percent٪)؛ وقتی پر شود کاربر تازه وصل نمی‌شود.',
    'recovered' => 'رفع شد: :label',
];
