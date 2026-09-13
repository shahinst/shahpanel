<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;
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
        {--admin-username= : نام کاربری مدیر (یا متغیر VPN_ADMIN_USERNAME)}
        {--admin-email= : ایمیل مدیر (یا متغیر VPN_ADMIN_EMAIL)}
        {--admin-password= : رمز عبور مدیر (یا متغیر VPN_ADMIN_PASSWORD)}
        {--admin-name= : نام کامل مدیر (یا متغیر VPN_ADMIN_NAME)}
        {--site-name= : نام سایت}
        {--site-url= : آدرس کامل سایت}';

    protected $description = 'ایجاد حساب مدیر و تکمیل تنظیمات پایه نصب (بدون نیاز به نصب‌کننده وب)';

    public function handle(): int
    {
        $username = $this->resolve('admin-username', 'VPN_ADMIN_USERNAME');
        $email = $this->resolve('admin-email', 'VPN_ADMIN_EMAIL');
        $password = $this->resolve('admin-password', 'VPN_ADMIN_PASSWORD', trim: false);
        $fullName = $this->resolve('admin-name', 'VPN_ADMIN_NAME') ?: 'مدیر سامانه';

        if ($username === '' || $email === '' || $password === '') {
            $this->error('نام کاربری، ایمیل و رمز عبور مدیر الزامی هستند.');

            return self::FAILURE;
        }

        if (mb_strlen($password) < 8) {
            $this->error('رمز عبور مدیر باید حداقل ۸ کاراکتر باشد.');

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('ایمیل مدیر معتبر نیست.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('users')) {
            $this->error('جدول users یافت نشد. ابتدا migrate را اجرا کنید.');

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
            $this->info("حساب مدیر موجود به‌روزرسانی شد: {$username}");
        } else {
            User::create($attributes + ['parent_id' => null]);
            $this->info("حساب مدیر ایجاد شد: {$username}");
        }

        $this->persistSettings();

        $this->info('نصب با موفقیت تکمیل شد.');

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
