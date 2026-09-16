<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;
use App\Support\PortalPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * تکمیل نصب بدون نصب‌کننده وب.
 *
 * حساب مدیر را می‌سازد (یا به‌روزرسانی می‌کند) و تنظیمات پایه را ذخیره می‌کند،
 * تا اسکریپت نصب سرور بتواند کل فرایند را بدون مراجعه به install.php کامل کند.
 *
 * اعتبارنامه‌های حساس از طریق متغیر محیطی خوانده می‌شوند تا در فهرست پردازه‌ها (ps) نشت نکنند:
 *   VPN_ADMIN_USERNAME, VPN_ADMIN_EMAIL, VPN_ADMIN_PASSWORD, VPN_ADMIN_NAME
 * مقادیر غیرحساس (نام/آدرس سایت) به‌صورت option پذیرفته می‌شوند.
 */
class InstallFinalizeCommand extends Command
{
    protected $signature = 'install:finalize
        {--admin-username= : Administrator username (or the VPN_ADMIN_USERNAME variable)}
        {--admin-email= : Administrator e-mail (or the VPN_ADMIN_EMAIL variable)}
        {--admin-password= : Administrator password (or the VPN_ADMIN_PASSWORD variable)}
        {--admin-name= : Administrator full name (or the VPN_ADMIN_NAME variable)}
        {--admin-path= : Private admin portal path, e.g. p4ba5e8c6f7}
        {--site-name= : Site name}
        {--site-url= : Full site URL}';

    protected $description = 'Create the administrator account and finish the base install settings';

    public function handle(): int
    {
        $username = $this->resolve('admin-username', 'VPN_ADMIN_USERNAME');
        $email = $this->resolve('admin-email', 'VPN_ADMIN_EMAIL');
        $password = $this->resolve('admin-password', 'VPN_ADMIN_PASSWORD', trim: false);
        $fullName = $this->resolve('admin-name', 'VPN_ADMIN_NAME') ?: 'مدیر سامانه';

        if ($username === '' || $email === '' || $password === '') {
            $this->error('Administrator username, e-mail and password are all required.');

            return self::FAILURE;
        }

        if (mb_strlen($password) < 8) {
            $this->error('The administrator password must be at least 8 characters long.');

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('The administrator e-mail is not a valid address.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('users')) {
            $this->error('The users table is missing. Run the migrations first.');

            return self::FAILURE;
        }

        $admin = User::query()
            ->where('username', $username)
            ->orWhere('email', $email)
            ->first();

        $attributes = [
            'role' => UserRole::Admin,
            'username' => $username,
            'email' => $email,
            'password' => $password, // cast 'hashed' روی مدل، هش را انجام می‌دهد
            'full_name' => $fullName,
            'status' => UserStatus::Active,
        ];

        if ($admin) {
            $admin->fill($attributes)->save();
            $this->info("Updated the existing administrator account: {$username}");
        } else {
            User::create($attributes + ['parent_id' => null]);
            $this->info("Created the administrator account: {$username}");
        }

        $this->persistSettings();

        $this->info('Installation completed successfully.');

        return self::SUCCESS;
    }

    private function persistSettings(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $siteName = trim((string) $this->option('site-name'));
        $siteUrl = rtrim(trim((string) $this->option('site-url')), '/');

        if ($siteName !== '') {
            Setting::setValue('site_name', $siteName);
        }

        if ($siteUrl !== '') {
            Setting::setValue('site_url', $siteUrl);
        }

        // مهاجرت 2026_05_25_000001 مقدار portal_path_admin را با «admin» پر می‌کند و
        // PortalPaths::all() بعد از نصب، مقدار جدول settings را بر config (و در نتیجه بر
        // VPN_ADMIN_PATH در .env) ترجیح می‌دهد. پس اگر مسیر تصادفی اینجا در دیتابیس ذخیره
        // نشود، بی‌صدا نادیده گرفته می‌شود و پنل روی /admin باقی می‌ماند.
        $adminPath = trim((string) $this->option('admin-path'));

        if ($adminPath !== '') {
            $slug = PortalPaths::sanitizeSlug($adminPath, '');

            if ($slug === '') {
                $this->warn("The admin portal path was invalid and has been ignored: {$adminPath}");
            } else {
                Setting::setValue('portal_path_admin', $slug);
                $this->info("Admin portal path set to: /{$slug}");
            }
        }

        if (Setting::getValue('installed_at') === null) {
            Setting::setValue('installed_at', now()->toIso8601String());
        }
    }

    private function resolve(string $option, string $envKey, bool $trim = true): string
    {
        $value = (string) ($this->option($option) ?: (getenv($envKey) ?: ''));

        return $trim ? trim($value) : $value;
    }
}
