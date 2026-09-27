<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * دسترسی بخش‌به‌بخشِ ادمین.
 *
 * این لایه فقط «کم می‌کند»: هیچ‌وقت به کسی اجازه‌ای نمی‌دهد که نقشش از قبل
 * نداشته باشد. برای نماینده/فروشنده/مشتری کاملاً بی‌اثر است و سلسله‌مراتب و
 * Policy‌های موجود دست‌نخورده می‌مانند.
 *
 * «مدیر اصلی» چطور شناسایی می‌شود؟ کم‌ترین id میان کاربرانِ نقش admin (حساب
 * حذف‌نشده). هیچ ستون یا پرچمی ذخیره نمی‌شود، چون هر چیزی که ذخیره شود
 * می‌تواند پاک/اشتباه شود و صاحب پنل را از تنظیم دسترسی‌ها بیرون بیندازد؛
 * ولی «کم‌ترین id» مشتق است: نه می‌شود از خود گرفت، نه دیگری می‌تواند آن را
 * دستکاری کند، و تا وقتی حتی یک ادمین در جدول باشد هرگز «بدون مدیر اصلی»
 * نمی‌شویم. حساب نصب‌کننده (اولین ادمینی که InstallFinalizeCommand ساخته)
 * همان صاحب پنل است، پس این تعریف با واقعیت می‌خواند.
 */
class AdminSectionAccessService
{
    protected bool $superAdminResolved = false;

    protected ?int $superAdminId = null;

    /**
     * کاتالوگ کامل — مبنای «سدّ» است، پس بدون فیلترِ ماژول/مسیر خوانده می‌شود:
     * مسیری که ثبت شده باید نگهبان داشته باشد.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sections(): array
    {
        $sections = config('admin_sections.sections', []);

        return is_array($sections) ? $sections : [];
    }

    /**
     * همان کاتالوگ، ولی فقط چیزهایی که روی این نصب واقعاً وجود دارند — برای
     * منو و فرم تیک‌ها. بخشی که مسیرش ثبت نشده یا ماژولش خاموش است نباید در
     * فرم دیده شود، وگرنه ادمین برای صفحه‌ای که نیست دسترسی تعیین می‌کند.
     *
     * @return array<string, array<string, mixed>>
     */
    public function availableSections(): array
    {
        $available = [];

        foreach ($this->sections() as $key => $section) {
            $children = [];

            foreach ((array) ($section['children'] ?? []) as $childKey => $child) {
                if ($this->entryAvailable($child)) {
                    $children[$childKey] = $child;
                }
            }

            $declaresChildren = ($section['children'] ?? []) !== [];

            // والدی که همهٔ فرزندانش ناموجودند، خودش هم چیزی برای نشان‌دادن ندارد.
            if ($declaresChildren && $children === []) {
                continue;
            }

            if (! $declaresChildren && ! $this->entryAvailable($section)) {
                continue;
            }

            $section['children'] = $children;
            $available[$key] = $section;
        }

        return $available;
    }

    public function isSuperAdmin(?User $user): bool
    {
        if ($user === null || $user->role !== UserRole::Admin) {
            return false;
        }

        return (int) $user->id === $this->superAdminId();
    }

    public function superAdminId(): ?int
    {
        if (! $this->superAdminResolved) {
            $this->superAdminResolved = true;

            $id = User::query()
                ->where('role', UserRole::Admin->value)
                ->orderBy('id')
                ->value('id');

            $this->superAdminId = $id === null ? null : (int) $id;
        }

        return $this->superAdminId;
    }

    /**
     * کلیدهای مجاز، یا null به معنای «همه‌چیز».
     *
     * null/خالی = همه‌چیز، عمداً: ادمین‌هایی که پیش از این به‌روزرسانی وجود
     * داشتند ستون خالی دارند و باید دقیقاً مثل امروز کار کنند. هیچ‌کس با یک
     * آپدیت از پنل بیرون نمی‌افتد.
     *
     * @return list<string>|null
     */
    public function grantedKeys(User $user): ?array
    {
        if ($user->role !== UserRole::Admin) {
            return null;
        }

        // مدیر اصلی همیشه همه‌چیز دارد، حتی اگر ستونش پر شده باشد؛ این آخرین
        // سدّ در برابر قفل‌شدنِ صاحب پنل روی پنل خودش است.
        if ($this->isSuperAdmin($user)) {
            return null;
        }

        $raw = $user->admin_section_permissions;

        if (! is_array($raw) || $raw === []) {
            return null;
        }

        return array_values(array_unique(array_map('strval', $raw)));
    }

    public function canAccessSection(?User $user, string $key): bool
    {
        if ($user === null || $user->role !== UserRole::Admin) {
            return true;
        }

        $granted = $this->grantedKeys($user);

        if ($granted === null) {
            return true;
        }

        return in_array($key, $granted, true);
    }

    /**
     * سدّ اصلی. نام مسیر با پیشوند admin. یا بدون آن پذیرفته می‌شود.
     */
    public function canAccessRoute(?User $user, string $routeName): bool
    {
        // این لایه فقط دربارهٔ ادمین حرف می‌زند؛ بقیهٔ نقش‌ها با نگهبان‌های
        // خودشان (role middleware و Policy‌ها) سنجیده می‌شوند.
        if ($user === null || $user->role !== UserRole::Admin) {
            return true;
        }

        $name = Str::startsWith($routeName, 'admin.')
            ? Str::after($routeName, 'admin.')
            : $routeName;

        // پیش از هر معافیتی: بخش‌های «فقط مدیر اصلی». اگر این شرط بعد از
        // بازگشتِ «دسترسی کامل» می‌آمد، هر ادمینِ قدیمیِ بدون محدودیت هم
        // می‌توانست صفحهٔ تعیین دسترسی‌ها را باز کند و خودش را ارتقا بدهد.
        if ($this->matchesAny($name, config('admin_sections.super_only', []))) {
            return $this->isSuperAdmin($user);
        }

        $granted = $this->grantedKeys($user);

        if ($granted === null) {
            return true;
        }

        if ($this->matchesAny($name, config('admin_sections.always_allowed', []))) {
            return true;
        }

        // اول فرزندها (دقیق‌تر)، بعد والد (سبد ته‌مانده).
        foreach ($this->sections() as $key => $section) {
            foreach ((array) ($section['children'] ?? []) as $childKey => $child) {
                if ($this->matchesAny($name, $child['routes'] ?? [])) {
                    return in_array($key, $granted, true)
                        && in_array($childKey, $granted, true);
                }
            }
        }

        foreach ($this->sections() as $key => $section) {
            if ($this->matchesAny($name, $section['routes'] ?? [])) {
                return in_array($key, $granted, true);
            }
        }

        // نقشه‌نشده = بسته. اگر روزی منوی تازه‌ای اضافه شد و در کاتالوگ ثبت
        // نشد، برای ادمینِ محدودشده فوراً ۴۰۳ می‌شود و دیده می‌شود؛ حالت
        // بازِ خاموش، بخشی بی‌نگهبان جا می‌گذاشت.
        return false;
    }

    /**
     * ورودی خام فرم را به فهرست کلیدهای معتبر و سازگار تبدیل می‌کند.
     *
     * @param  array<int|string, mixed>  $input
     * @return list<string>
     */
    public function sanitize(array $input): array
    {
        $sections = $this->sections();
        $picked = [];

        foreach ($input as $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $key = (string) $value;

            if (array_key_exists($key, $sections)) {
                $picked[$key] = true;

                continue;
            }

            foreach ($sections as $parentKey => $section) {
                if (array_key_exists($key, (array) ($section['children'] ?? []))) {
                    $picked[$key] = true;
                    // زیربخش بدون والد بی‌معنی است: مسیرهای مشترکِ بخش (مثل
                    // نمایش یا ویرایش یک اکانت) زیر کلید والد نگهبانی می‌شوند،
                    // پس بدون والد صفحهٔ زیربخش نیمه‌کاره ۴۰۳ می‌گرفت.
                    $picked[$parentKey] = true;
                    break;
                }
            }
        }

        // تیکِ والد بدون هیچ فرزندی = «همهٔ زیربخش‌ها». اگر ادمین خودش چند
        // فرزند را انتخاب کرده باشد، انتخاب صریح او محترم است.
        foreach ($sections as $parentKey => $section) {
            $children = array_keys((array) ($section['children'] ?? []));

            if ($children === [] || ! isset($picked[$parentKey])) {
                continue;
            }

            $hasChild = false;

            foreach ($children as $childKey) {
                if (isset($picked[$childKey])) {
                    $hasChild = true;
                    break;
                }
            }

            if ($hasChild) {
                continue;
            }

            foreach ($children as $childKey) {
                $picked[$childKey] = true;
            }
        }

        // بخش‌های همیشگی (داشبورد) را هیچ فرمی نمی‌تواند حذف کند؛ وگرنه ادمین
        // بعد از ورود روی صفحهٔ فرودش ۴۰۳ می‌گرفت.
        foreach ($sections as $key => $section) {
            if (($section['always'] ?? false) === true) {
                $picked[$key] = true;
            }
        }

        // خروجی به ترتیب کاتالوگ، تا مقدار ذخیره‌شده پایدار و خوانا بماند.
        $ordered = [];

        foreach ($sections as $key => $section) {
            if (isset($picked[$key])) {
                $ordered[] = $key;
            }

            foreach (array_keys((array) ($section['children'] ?? [])) as $childKey) {
                if (isset($picked[$childKey])) {
                    $ordered[] = $childKey;
                }
            }
        }

        return $ordered;
    }

    /**
     * @param  array<int, string>|string  $patterns
     */
    protected function matchesAny(string $name, array|string $patterns): bool
    {
        $patterns = array_values(array_filter((array) $patterns, 'is_string'));

        if ($patterns === []) {
            return false;
        }

        return Str::is($patterns, $name);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function entryAvailable(array $entry): bool
    {
        $module = $entry['module'] ?? null;

        if (is_string($module) && $module !== '' && ! module_active($module)) {
            return false;
        }

        $probe = $entry['probe'] ?? null;

        if (! is_string($probe) || $probe === '') {
            return true;
        }

        return Route::has('admin.'.$probe);
    }
}
