<?php

return [
    'page_title' => 'System Settings',
    'section_general' => 'General',
    'section_payment' => 'Payment & Top-up',
    'section_system' => 'System',

    'fields' => [
        'site_name' => 'Site name',
        'site_url' => 'Site URL',
        'timezone' => 'Timezone',
        'currency' => 'Currency code',
        'currency_label' => 'Currency label',
        'accent_color' => 'Accent color',
        'support_phone' => 'Support phone',
        'support_telegram' => 'Support Telegram',
        'default_payment_card' => 'Default card number',
        'min_charge_amount' => 'Minimum charge amount',
        'sync_interval_minutes' => 'Traffic sync interval (minutes)',
        'portal_enabled' => 'Client portal enabled',
        'registration_enabled' => 'Self registration',
    ],

    'hints' => [
        'site_name' => 'Shown in page titles and emails.',
        'site_url' => 'Full URL with https.',
        'timezone' => 'Recommended: Asia/Tehran',
        'currency_label' => 'e.g. Toman',
        'support_telegram' => 'Without @',
        'default_payment_card' => 'Card number for card-to-card payments.',
        'min_charge_amount' => 'Minimum top-up request amount.',
        'sync_interval_minutes' => 'Should match cron (default 5).',
    ],

    'options' => [
        'yes' => 'Yes',
        'no' => 'No',
    ],
];
