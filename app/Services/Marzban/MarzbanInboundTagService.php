<?php

namespace App\Services\Marzban;

use App\Enums\ServiceType;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\User;
use App\Services\PackageService;
use App\Services\UserPackageAssignmentService;
use Illuminate\Support\Collection;

/**
 * پل بین «پکیج + بازهٔ پکیج» پنل و مفهوم inbound در مرزبان.
 *
 * چرا: ربات‌های مرزبان‌محور (میرزا و ویزویز) هیچ تصوری از پکیج ندارند؛ تنها
 * چیزی که در ساخت پلن انتخاب و بعداً عیناً پس فرستاده می‌شود، tag یک inbound
 * است. پس هر ترکیب «پکیج + بازه» به‌شکل یک inbound تبلیغ می‌شود و انتخاب پلن
 * توسط فروشنده در ربات، خودبه‌خود همان پکیج و بازه را انتخاب می‌کند.
 *
 * قالب tag:
 *
 *     shp_p{packageId}_d{durationId}[_{label}]
 *
 * تنها دو شناسهٔ عددی معتبرند؛ label فقط برای خوانایی آدم است (مثل 30d-50g) و
 * هنگام خواندن نادیده گرفته می‌شود. نتیجه‌اش این است که تغییر نام یا حجم پکیج،
 * tagهای ذخیره‌شده در دیتابیس ربات را نمی‌شکند.
 */
class MarzbanInboundTagService
{
    /** فقط ASCII و بدون فاصله، چون tag در دیتابیس و پیام‌های ربات می‌چرخد. */
    protected const TAG_REGEX = '/^shp_p(\d+)_d(\d+)(?:_|$)/';

    public function __construct(
        protected UserPackageAssignmentService $assignments,
        protected PackageService $packages,
    ) {}

    /**
     * سرویس‌هایی که از این مسیر قابل فروش‌اند.
     *
     * میکروتیک (PPP/WireGuard/OpenVPN/L2TP) و خانوادهٔ AnyConnect (سیسکو و
     * ocserv) مفهوم «لینک کانفیگ» ندارند؛ ربات مرزبانی حتماً links یا
     * subscription_url می‌خواهد، پس پکیج‌شان از همان اول تبلیغ نمی‌شود تا خطا
     * به لحظهٔ ساخت اکانت موکول نشود.
     */
    public function supports(ServiceType $serviceType): bool
    {
        return $serviceType->isPanelV2ray();
    }

    /**
     * پروتکلی که برای نوع سرویس پکیج به ربات اعلام می‌شود.
     *
     * برای پنل‌های چندپروتکلی (پاسارگارد/رمناویو) vless انتخاب شده چون رایج‌ترین
     * حالت است و ربات فقط برای گروه‌بندی و نمایش از آن استفاده می‌کند.
     */
    public function protocolFor(?ServiceType $serviceType): string
    {
        return match ($serviceType) {
            ServiceType::SanaeiVmess => 'vmess',
            ServiceType::SanaeiTrojan => 'trojan',
            default => 'vless',
        };
    }

    public function tagFor(Package $package, PackageDuration $duration): string
    {
        $tag = 'shp_p'.((int) $package->id).'_d'.((int) $duration->id);
        $label = $this->label($package, $duration);

        return $label === '' ? $tag : $tag.'_'.$label;
    }

    /**
     * فهرست inboundهای تبلیغ‌شده برای این نماینده — یک ردیف به ازای هر
     * «پکیج + بازهٔ فعال».
     *
     * @return list<array{tag: string, protocol: string, package: Package, duration: PackageDuration}>
     */
    public function advertised(User $agent): array
    {
        /** @var Collection<int, Package> $packages */
        $packages = $this->assignments->assignedPackagesQuery($agent)
            ->with('durations')
            ->get();

        $rows = [];

        foreach ($packages as $package) {
            if (! $this->sellable($package)) {
                continue;
            }

            foreach ($this->packages->enabledDurationsFor($package) as $duration) {
                $rows[] = [
                    'tag' => $this->tagFor($package, $duration),
                    'protocol' => $this->protocolFor($package->service_type),
                    'package' => $package,
                    'duration' => $duration,
                ];
            }
        }

        return $rows;
    }

    /**
     * پکیجی که اصلاً اجازهٔ تبلیغ‌شدن دارد.
     *
     * KYC هم کنار گذاشته می‌شود: تأیید هویت در ربات قابل انجام نیست و
     * createAccount در نهایت آن را رد می‌کند، پس بهتر است هرگز پیشنهاد نشود.
     */
    public function sellable(Package $package): bool
    {
        if (! $package->service_type instanceof ServiceType || ! $this->supports($package->service_type)) {
            return false;
        }

        return ! (bool) $package->kyc_required;
    }

    /**
     * tag را به «پکیج + بازه» برمی‌گرداند، یا null اگر tag بدشکل باشد، پکیج/بازه
     * وجود نداشته باشد، یا پکیج به این نماینده تخصیص داده نشده باشد.
     *
     * محدودسازی به پکیج‌های تخصیص‌یافته همین‌جا انجام می‌شود تا یک tag دست‌ساز
     * نتواند پکیجِ نمایندهٔ دیگری را بفروشد.
     *
     * @return array{0: Package, 1: PackageDuration}|null
     */
    public function resolve(User $agent, string $tag): ?array
    {
        if (preg_match(self::TAG_REGEX, trim($tag), $matches) !== 1) {
            return null;
        }

        $packageId = (int) $matches[1];
        $durationId = (int) $matches[2];

        if (! $this->assignments->userHasPackage($agent, $packageId)) {
            return null;
        }

        $package = Package::query()->with('durations')->find($packageId);

        if ($package === null) {
            return null;
        }

        $duration = PackageDuration::query()
            ->where('package_id', $packageId)
            ->where('id', $durationId)
            ->where('is_enabled', true)
            ->first();

        return $duration === null ? null : [$package, $duration];
    }

    /**
     * اولین tag قابل‌تفسیر را از بدنهٔ ساخت/به‌روزرسانی کاربر بیرون می‌کشد.
     *
     * شکل inbounds در مرزبان {"vless": ["tag", ...]} است، ولی ربات‌ها گاهی
     * فهرست تخت یا حتی یک رشته می‌فرستند؛ هر سه پذیرفته می‌شود چون هدف فقط
     * پیدا کردن tag است.
     *
     * @return array{0: Package, 1: PackageDuration}|null
     */
    public function resolveFromInbounds(User $agent, mixed $inbounds): ?array
    {
        foreach ($this->flattenTags($inbounds) as $tag) {
            $resolved = $this->resolve($agent, $tag);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function flattenTags(mixed $inbounds): array
    {
        if (is_string($inbounds)) {
            return trim($inbounds) === '' ? [] : [trim($inbounds)];
        }

        if (! is_array($inbounds)) {
            return [];
        }

        $tags = [];

        foreach ($inbounds as $value) {
            foreach ($this->flattenTags($value) as $tag) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * برچسب خوانا و کاملاً ASCII: مدت + حجم + (اگر نام پکیج لاتین باشد) نام.
     *
     * نام فارسی عمداً حذف می‌شود؛ tag در جدول‌های ربات و پیام‌های متنی می‌چرخد
     * و نگه‌داشتن آن به ASCII، هر جای زنجیره را از مشکل encoding ایمن می‌کند.
     */
    protected function label(Package $package, PackageDuration $duration): string
    {
        $parts = [$this->durationSlug($duration), $this->volumeSlug($package)];

        $name = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $package->name), '-');

        if ($name !== '') {
            $parts[] = substr($name, 0, 24);
        }

        return implode('-', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    protected function durationSlug(PackageDuration $duration): string
    {
        $hours = $duration->tier->durationHours();

        if ($hours === null) {
            return 'notime';
        }

        return $hours % 24 === 0 ? ((int) ($hours / 24)).'d' : $hours.'h';
    }

    protected function volumeSlug(Package $package): string
    {
        if ($package->isElastic()) {
            return 'elastic';
        }

        if ($package->isUnlimited()) {
            return 'unlimited';
        }

        $gb = (float) $package->data_limit_gb;

        return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.').'g';
    }
}
