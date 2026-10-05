<?php

/*
 * فهرست بخش‌های پنل ادمین — «مرجع یگانه» برای سه مصرف‌کننده:
 *
 *   ۱) منوی کنار صفحه (resources/views/layouts/partials/nav-admin*.blade.php)
 *   ۲) فرم تیک‌زدن دسترسی‌ها (resources/views/admin/administrators/_form.blade.php)
 *   ۳) سدّ واقعی روی مسیرها (App\Http\Middleware\EnsureAdminSectionAccess)
 *
 * چون هر سه از همین فایل می‌خوانند، اضافه‌شدن یک آیتم منو بدون ثبت در اینجا
 * باعث نمی‌شود بخشی «بی‌نگهبان» بماند: هر مسیرِ گروه ادمین که با هیچ الگویی
 * اینجا نخورد، برای ادمینِ محدودشده ۴۰۳ می‌گیرد (بستنِ پیش‌فرض). ادمین‌های
 * نامحدود (دسترسی کامل) هیچ‌وقت به این شاخه نمی‌رسند، پس چنین اشتباهی پنلِ
 * موجود را نمی‌شکند و فقط سرِ ادمینِ محدودشده دیده می‌شود.
 *
 * ساختار هر بخش:
 *   label    کلید ترجمه (نه متن) — چون این فایل با config:cache کش می‌شود و
 *            __() در زمان کش، زبانِ همان لحظه را برای همیشه می‌بندد.
 *   icon     آیکن boxicons، همان که منو استفاده می‌کند.
 *   probe    یک نام مسیر (بدون پیشوند admin.) که وجودش نشان می‌دهد این بخش
 *            روی این نصب واقعاً هست؛ مثل همان Route::has که خود منو دارد.
 *   module   اسلاگ ماژول؛ با خاموش‌بودن ماژول، بخش از منو و فرم حذف می‌شود.
 *   routes   الگوهای نام مسیر که این بخش را می‌سازند (* مجاز است).
 *   children زیربخش‌ها، با همان ساختار.
 *   always   بخشی که گرفتنش از کسی ممکن نیست (داشبورد: صفحهٔ فرودِ هر ادمین).
 *
 * الگوهای routes روی «والد» نقش سبدِ ته‌مانده را دارند: مسیری که به هیچ فرزندی
 * نخورد با کلید والد نگهبانی می‌شود. مثلاً accounts.show بین چهار نوع اکانت
 * مشترک است و فقط زیر کلید accounts قابل کنترل است.
 */
return [

    // مسیرهایی که به هیچ بخشی وابسته نیستند و گرفتن‌شان معنی ندارد: هر ادمینی
    // باید بتواند پروفایل و ورود دومرحله‌ای خودش را ببیند، وگرنه یک ادمینِ
    // محدودشده حتی نمی‌تواند رمز خودش را عوض کند.
    'always_allowed' => [
        'profile.*',
        'two-factor.*',
        // بستنِ پیام «ستاره بدهید» کار هر ادمینی است که آن را می‌بیند. اگر اینجا
        // نباشد، ادمینِ با دسترسی محدود برای بستن یک پیام ۴۰۳ می‌گیرد و پیام
        // هم بسته نمی‌شود.
        'star-prompt.*',
    ],

    // فقط «مدیر اصلی». این‌ها حتی برای ادمینِ بدون محدودیت هم بسته‌اند، چون
    // اینجا جایی است که دسترسی بقیه تعیین می‌شود؛ اگر باز بود، هر ادمینی
    // می‌توانست دسترسی خودش را گسترش بدهد.
    'super_only' => [
        'administrators.*',
        // Updating the panel is the owner's call, like managing administrators.
        'updates.*',
        // The Telegram tunnel changes the server's routing as root.
        'tgtunnel.*',
    ],

    'sections' => [

        'dashboard' => [
            'label' => 'menu.dashboard',
            'icon' => 'bx-home-alt',
            'probe' => 'dashboard',
            'always' => true,
            'routes' => ['dashboard', 'dashboard.*'],
        ],

        'agents' => [
            'label' => 'menu.agents',
            'icon' => 'bx-user-pin',
            'probe' => 'users.index',
            'routes' => ['users.*', 'inbound-allocations.*', 'inbound-agents.*', 'dedicated.*', 'markup.*'],
        ],

        'sellers' => [
            'label' => 'menu.sellers',
            'icon' => 'bx-user',
            'probe' => 'sellers.index',
            'routes' => ['sellers.*'],
        ],

        'clients' => [
            'label' => 'menu.clients',
            'icon' => 'bx-group',
            'probe' => 'clients.index',
            'routes' => ['clients.*'],
        ],

        'packages' => [
            'label' => 'menu.packages',
            'icon' => 'bx-package',
            'probe' => 'packages.index',
            'routes' => ['packages.*', 'package-categories.*'],
        ],

        'tunneling' => [
            'label' => 'menu.tunneling',
            'icon' => 'bx-git-branch',
            'probe' => 'tunneling.index',
            'module' => 'tunneling',
            'routes' => ['tunneling.*'],
        ],

        'shahbot' => [
            'label' => 'shahbot::admin.menu',
            'icon' => 'bxl-telegram',
            'probe' => 'shahbot.index',
            'module' => 'shahbot',
            'routes' => ['shahbot.*'],
        ],

        'accounts' => [
            'label' => 'menu.accounts',
            'icon' => 'bx-group',
            'routes' => ['accounts.*'],
            'children' => [
                'accounts_wireguard' => [
                    'label' => 'menu.accounts_wireguard',
                    'icon' => 'bx-shield-quarter',
                    'probe' => 'accounts.wireguard',
                    'routes' => ['accounts.wireguard'],
                ],
                'accounts_ppp' => [
                    'label' => 'menu.accounts_ppp',
                    'icon' => 'bx-plug',
                    'probe' => 'accounts.ppp',
                    'routes' => ['accounts.ppp'],
                ],
                'accounts_v2ray' => [
                    'label' => 'menu.accounts_v2ray',
                    'icon' => 'bx-rocket',
                    'probe' => 'accounts.v2ray',
                    'routes' => ['accounts.v2ray'],
                ],
                'accounts_anyconnect' => [
                    'label' => 'menu.accounts_anyconnect',
                    'icon' => 'bx-network-chart',
                    'probe' => 'accounts.anyconnect',
                    'routes' => ['accounts.anyconnect'],
                ],
            ],
        ],

        'financial' => [
            'label' => 'menu.financial',
            'icon' => 'bx-wallet',
            'routes' => [
                'accounting.*',
                'financial-plan-templates.*',
                'agent-financial-plans.*',
                'payment-requests.*',
                'client-pricing.*',
            ],
            'children' => [
                'financial_accounting' => [
                    'label' => 'menu.accounting',
                    'icon' => 'bx-calculator',
                    'probe' => 'accounting.index',
                    'routes' => ['accounting.*'],
                ],
                'financial_plan_templates' => [
                    'label' => 'financial_plans.menu_templates',
                    'icon' => 'bx-layer',
                    'probe' => 'financial-plan-templates.index',
                    'routes' => ['financial-plan-templates.*'],
                ],
                'financial_agent_plans' => [
                    'label' => 'financial_plans.menu_purchases',
                    'icon' => 'bx-transfer',
                    'probe' => 'agent-financial-plans.index',
                    'routes' => ['agent-financial-plans.*'],
                ],
                'financial_payment_requests' => [
                    'label' => 'menu.payment_requests',
                    'icon' => 'bx-money',
                    'probe' => 'payment-requests.index',
                    'routes' => ['payment-requests.*'],
                ],
                'financial_client_pricing' => [
                    'label' => 'clients.display_pricing',
                    'icon' => 'bx-purchase-tag',
                    'probe' => 'client-pricing.edit',
                    'routes' => ['client-pricing.*'],
                ],
            ],
        ],

        'support' => [
            'label' => 'tickets.page_title',
            'icon' => 'bx-headphone',
            'routes' => ['tickets.*'],
            'children' => [
                'support_tickets' => [
                    'label' => 'tickets.page_title',
                    'icon' => 'bx-support',
                    'probe' => 'tickets.index',
                    'routes' => [
                        'tickets.index',
                        'tickets.create',
                        'tickets.store',
                        'tickets.show',
                        'tickets.reply',
                        'tickets.status',
                    ],
                ],
                'support_departments' => [
                    'label' => 'tickets.departments',
                    'icon' => 'bx-folder',
                    'probe' => 'tickets.departments.index',
                    'routes' => ['tickets.departments.*'],
                ],
            ],
        ],

        'reports' => [
            'label' => 'menu.reports',
            'icon' => 'bx-bar-chart-alt-2',
            'probe' => 'reports.index',
            'routes' => ['reports.*'],
        ],

        'automation' => [
            'label' => 'menu.automation',
            'icon' => 'bx-bot',
            'routes' => [
                'automation.*',
                'settings.logs',
                'settings.logs.*',
                'settings.server-backups.*',
                'broadcasts.*',
                'client-payment-card.*',
                'payment-cards.*',
                'maintenance.*',
            ],
            'children' => [
                'automation_pricing' => [
                    'label' => 'menu.automation_pricing',
                    'icon' => 'bx-purchase-tag',
                    'probe' => 'automation.pricing',
                    'routes' => ['automation.pricing', 'automation.pricing.*'],
                ],
                'automation_portal' => [
                    'label' => 'menu.automation_portal',
                    'icon' => 'bx-mobile-alt',
                    'probe' => 'automation.portal',
                    'routes' => ['automation.portal', 'automation.portal.*'],
                ],
                'automation_cron' => [
                    'label' => 'menu.automation_cron',
                    'icon' => 'bx-time-five',
                    'probe' => 'automation.index',
                    'routes' => ['automation.index', 'automation.install'],
                ],
                'automation_logs' => [
                    'label' => 'menu.settings_logs',
                    'icon' => 'bx-file-find',
                    'probe' => 'settings.logs',
                    'routes' => ['settings.logs', 'settings.logs.*'],
                ],
                'automation_server_backups' => [
                    'label' => 'menu.settings_server_backups',
                    'icon' => 'bx-cloud-download',
                    'probe' => 'settings.server-backups.index',
                    'routes' => ['settings.server-backups.*'],
                ],
                'automation_broadcasts' => [
                    'label' => 'menu.broadcasts',
                    'icon' => 'bx-broadcast',
                    'probe' => 'broadcasts.index',
                    'routes' => ['broadcasts.*'],
                ],
                'automation_payment_card' => [
                    'label' => 'clients.payment_card_settings',
                    'icon' => 'bx-credit-card',
                    'probe' => 'client-payment-card.edit',
                    'routes' => ['client-payment-card.*', 'payment-cards.*'],
                ],
                'automation_maintenance' => [
                    'label' => 'menu.maintenance',
                    'icon' => 'bx-data',
                    'probe' => 'maintenance.index',
                    'routes' => ['maintenance.*'],
                ],
            ],
        ],

        'settings' => [
            'label' => 'menu.settings',
            'icon' => 'bx-cog',
            'routes' => [
                'settings.index',
                'settings.update',
                'modules.*',
                'gift-accounts.*',
                'gifts.*',
                'sms.*',
                'kyc.*',
                'payment-gateways.*',
                'gateway-payments.*',
                'security.*',
                'login-firewall.*',
                'web-shield.*',
                'api-tokens.*',
                'servers.*',
                'migrate.*',
            ],
            'children' => [
                'settings_general' => [
                    'label' => 'menu.settings_general',
                    'icon' => 'bx-cog',
                    'probe' => 'settings.index',
                    'routes' => ['settings.index', 'settings.update'],
                ],
                'settings_modules' => [
                    'label' => 'menu.modules',
                    'icon' => 'bx-extension',
                    'probe' => 'modules.index',
                    'routes' => ['modules.*'],
                ],
                'settings_gift_accounts' => [
                    'label' => 'menu.gift_accounts',
                    'icon' => 'bx-gift',
                    'probe' => 'gift-accounts.index',
                    'routes' => ['gift-accounts.*'],
                ],
                'settings_gifts' => [
                    'label' => 'menu.gifts',
                    'icon' => 'bx-heart',
                    'probe' => 'gifts.index',
                    'routes' => ['gifts.*'],
                ],
                'settings_sms' => [
                    'label' => 'menu.sms',
                    'icon' => 'bx-message-dots',
                    'probe' => 'sms.index',
                    'routes' => ['sms.*'],
                ],
                'settings_kyc' => [
                    'label' => 'menu.kyc',
                    'icon' => 'bx-id-card',
                    'probe' => 'kyc.settings',
                    'routes' => ['kyc.settings', 'kyc.settings.*'],
                ],
                'settings_kyc_documents' => [
                    'label' => 'menu.kyc_documents',
                    'icon' => 'bx-folder-open',
                    'probe' => 'kyc.index',
                    'routes' => ['kyc.index', 'kyc.show', 'kyc.document', 'kyc.reset'],
                ],
                'settings_payment_gateways' => [
                    'label' => 'payment_gateways.menu_label',
                    'icon' => 'bx-credit-card-front',
                    'probe' => 'payment-gateways.index',
                    'module' => 'payments',
                    'routes' => ['payment-gateways.*'],
                ],
                'settings_gateway_payments' => [
                    'label' => 'payment_gateways.menu_history',
                    'icon' => 'bx-history',
                    'probe' => 'gateway-payments.index',
                    'module' => 'payments',
                    'routes' => ['gateway-payments.*'],
                ],
                'settings_security' => [
                    'label' => 'security.hub_title',
                    'icon' => 'bx-shield-quarter',
                    'probe' => 'security.index',
                    'routes' => ['security.*', 'login-firewall.*', 'web-shield.*'],
                ],
                'settings_api_tokens' => [
                    'label' => 'api.admin_menu',
                    'icon' => 'bx-plug',
                    'probe' => 'api-tokens.index',
                    'routes' => ['api-tokens.*'],
                ],
                'settings_servers' => [
                    'label' => 'menu.servers',
                    'icon' => 'bx-server',
                    'probe' => 'servers.index',
                    'routes' => ['servers.*'],
                ],
                'settings_migrate' => [
                    'label' => 'menu.migrate',
                    'icon' => 'bx-transfer-alt',
                    'probe' => 'migrate.index',
                    'module' => 'migrate',
                    'routes' => ['migrate.*'],
                ],
            ],
        ],
    ],
];
