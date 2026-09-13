<?php

return [
    'default_provider' => env('KYC_PROVIDER', 'api_ir'),

    'max_verify_attempts' => 2,

    'document_disk' => 'kyc_private',
    'document_directory' => 'documents',
    'document_max_kb' => 5120,
    'document_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],

    'api_ir' => [
        'base_url' => rtrim((string) env('KYC_API_IR_BASE_URL', 'https://s.api.ir'), '/'),
        'timeout_seconds' => max(10, (int) env('KYC_API_IR_TIMEOUT_SECONDS', 45)),
        'connect_timeout_seconds' => max(5, (int) env('KYC_API_IR_CONNECT_TIMEOUT_SECONDS', 15)),
        // Optional dedicated credit endpoint if/when api.ir exposes one.
        'credit_paths' => array_values(array_filter(array_map(
            static fn ($path) => trim((string) $path, '/'),
            explode(',', (string) env(
                'KYC_API_IR_CREDIT_PATHS',
                'api/Account/Credit,api/Account/GetCredit,api/Developer/Credit,api/Wallet/Credit'
            ))
        ))),
    ],
];
