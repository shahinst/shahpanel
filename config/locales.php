<?php

/*
 * Languages the panel ships in.
 *
 * `dir` drives both the <html dir> attribute and which compiled stylesheet is
 * loaded, so adding a language here is all that is needed for the switcher,
 * the layouts and the digit formatting to pick it up.
 *
 * `digits` says which numeral set that language reads naturally. Persian is the
 * only one that uses Eastern Arabic numerals; the rest keep Latin digits.
 */
return [
    'supported' => [
        'fa' => [
            'name' => 'فارسی',
            'english_name' => 'Persian',
            'dir' => 'rtl',
            'digits' => 'fa',
        // برچسب BCP47 برای Intl در مرورگر — تعیین می‌کند ارقام با چه خطی نوشته شوند.
        'tag' => 'fa-IR',
            'currency' => 'IRT',
        ],
        'en' => [
            'name' => 'English',
            'english_name' => 'English',
            'dir' => 'ltr',
            'digits' => 'latn',
        // برچسب BCP47 برای Intl در مرورگر — تعیین می‌کند ارقام با چه خطی نوشته شوند.
        'tag' => 'en-US',
            'currency' => 'USD',
        ],
        'ru' => [
            'name' => 'Русский',
            'english_name' => 'Russian',
            'dir' => 'ltr',
            'digits' => 'latn',
        // برچسب BCP47 برای Intl در مرورگر — تعیین می‌کند ارقام با چه خطی نوشته شوند.
        'tag' => 'ru-RU',
            'currency' => 'USD',
        ],
        'zh' => [
            'name' => '中文',
            'english_name' => 'Chinese',
            'dir' => 'ltr',
            'digits' => 'latn',
        // برچسب BCP47 برای Intl در مرورگر — تعیین می‌کند ارقام با چه خطی نوشته شوند.
        'tag' => 'zh-CN',
            'currency' => 'USD',
        ],
    ],

    // Where the chosen language is remembered for a guest.
    'session_key' => 'app_locale',
];
