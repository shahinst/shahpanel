<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Default panel settings (overwritten during web installer step 6).
     */
    public function run(): void
    {
        $now = now();
        $defaults = [
            'site_name' => env('APP_NAME', 'سامانه امور مشتریان'),
            'site_url' => env('APP_URL', 'http://localhost'),
            'timezone' => env('APP_TIMEZONE', 'Asia/Tehran'),
            'currency' => env('VPN_CURRENCY', 'IRT'),
            'currency_label' => env('VPN_CURRENCY_LABEL', 'تومان'),
            'accent_color' => env('VPN_ACCENT_COLOR', '#2563eb'),
            'support_phone' => '',
            'support_telegram' => '',
            'default_payment_card' => '',
            'min_charge_amount' => '10000',
            'global_discount_enabled' => '0',
            'global_discount_percent' => '0',
            'global_discount_ends_at' => '',
            'sync_interval_minutes' => (string) env('VPN_SYNC_INTERVAL', 5),
            'default_agent_daily_server_changes' => '5',
            'portal_enabled' => '1',
            'registration_enabled' => '0',
            'ticket_auto_close_days' => '7',
            'portal_path_admin' => 'admin',
            'portal_path_agent' => 'agent',
            'portal_path_seller' => 'seller',
            'block_legacy_portal_paths' => '1',
        ];

        foreach ($defaults as $key => $value) {
            \App\Models\Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'updated_at' => $now],
            );
        }
    }
}
