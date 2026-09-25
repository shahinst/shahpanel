<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AppStoreIconService
{
    /**
     * SVG عمداً از این فهرست حذف شده است: یک آیکون SVG می‌تواند <script> داشته
     * باشد و چون روی دامنهٔ خودِ پنل و بدون احراز هویت سرو می‌شد، به XSS ذخیره‌شده
     * روی همان مبدأ (با نشست کاربر بازدیدکننده) تبدیل می‌شد.
     */
    public const STORED_ICON_PATTERN = '/^[a-f0-9]{40}\.(png|jpe?g|gif|webp|ico)$/i';

    public function resolveAndStore(string $appUrl): string
    {
        $appUrl = trim($appUrl);

        if ($appUrl === '') {
            return $this->fallbackIconUrl($appUrl);
        }

        return $this->storeFromSource($appUrl) ?: $this->fallbackIconUrl($appUrl);
    }

    /**
     * Download and store an icon from a direct image URL, app-store page, or favicon.
     */
    public function storeFromSource(string $sourceUrl): string
    {
        $sourceUrl = trim($sourceUrl);

        if ($sourceUrl === '') {
            return '';
        }

        if ($stored = $this->downloadAndStore($sourceUrl)) {
            return $stored;
        }

        $resolved = $this->resolveRemoteIconUrl($sourceUrl);

        if ($resolved !== $sourceUrl && ($stored = $this->downloadAndStore($resolved, $sourceUrl))) {
            return $stored;
        }

        $favicon = $this->fallbackIconUrl($sourceUrl);

        if ($favicon !== $sourceUrl && ($stored = $this->downloadAndStore($favicon, $sourceUrl))) {
            return $stored;
        }

        return '';
    }

    /**
     * Download icon from a direct image URL (or page URL resolved elsewhere).
     */
    public function downloadAndStore(string $remoteUrl, ?string $cacheKey = null): ?string
    {
        $remoteUrl = trim($remoteUrl);

        if ($remoteUrl === '') {
            return null;
        }

        $cacheKey = $cacheKey ?? $remoteUrl;

        // SSRF: بدون این بررسی یک مدیر می‌توانست پنل را وادار کند
        // 169.254.169.254 (متادیتای ابر) یا 127.0.0.1:2053 (پنل محلی) را بخواند و
        // پاسخ آن روی یک نشانی عمومیِ آیکون خوانده شود.
        if (! self::isFetchableUrl($remoteUrl)) {
            return null;
        }

        try {
            $response = Http::timeout(12)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                ])
                ->withOptions(['allow_redirects' => self::safeRedirectOptions()])
                ->get($remoteUrl);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();

            if (! $this->isLikelyImagePayload($body, (string) $response->header('Content-Type'))) {
                return null;
            }

            $ext = $this->guessExtension((string) $response->header('Content-Type'), $remoteUrl, $body);
            $filename = sha1($cacheKey).'.'.$ext;
            $path = self::storedRelativePath($filename);

            Storage::disk('public')->makeDirectory('portal-icons');
            Storage::disk('public')->put($path, $body);

            return self::publicUrlForFilename($filename);
        } catch (Throwable) {
            return null;
        }
    }

    public static function isStoredIconFilename(string $filename): bool
    {
        return (bool) preg_match(self::STORED_ICON_PATTERN, $filename);
    }

    public static function storedRelativePath(string $filename): string
    {
        return 'portal-icons/'.$filename;
    }

    public static function publicUrlForFilename(string $filename): string
    {
        return route('portal-icons.show', ['filename' => $filename]);
    }

    public static function normalizePublicUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return $url;
        }

        if (preg_match('#/(?:storage/)?portal-icons/([a-f0-9]{40}\.(?:png|jpe?g|gif|webp|ico))(?:\?.*)?$#i', $url, $matches)) {
            return self::publicUrlForFilename($matches[1]);
        }

        return $url;
    }

    public static function storedFileExists(?string $publicUrl): bool
    {
        $publicUrl = trim((string) $publicUrl);

        if ($publicUrl === '') {
            return false;
        }

        if (preg_match('#/portal-icons/([a-f0-9]{40}\.(?:png|jpe?g|gif|webp|ico))(?:\?.*)?$#i', $publicUrl, $matches)) {
            return Storage::disk('public')->exists(self::storedRelativePath($matches[1]));
        }

        return false;
    }

    public static function displayUrlForCategory(array $category): string
    {
        $iconUrl = trim((string) ($category['icon_url'] ?? ''));

        if ($iconUrl !== '') {
            $normalized = self::normalizePublicUrl($iconUrl);

            if (self::storedFileExists($normalized) || ! self::isLocalPortalIconUrl($normalized)) {
                return $normalized;
            }
        }

        $sourceUrl = trim((string) ($category['icon_source_url'] ?? ''));

        if ($sourceUrl !== '') {
            return $sourceUrl;
        }

        return '';
    }

    public static function looksLikeDirectImageUrl(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return (bool) preg_match('/\.(png|jpe?g|webp|svg|ico|gif)(?:$|\?)/', $path);
    }

    public static function isLocalPortalIconUrl(string $url): bool
    {
        return (bool) preg_match('#/portal-icons/[a-f0-9]{40}\.#i', $url);
    }

    public function resolveRemoteIconUrl(string $appUrl): string
    {
        $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));

        if (str_contains($host, 'play.google.com')
            || str_contains($host, 'apps.apple.com')
            || str_contains($host, 'itunes.apple.com')) {
            // همان محافظت SSRF مسیر دانلود؛ تطبیق میزبان با str_contains نشانی
            // هایی مثل play.google.com.attacker.internal را هم می‌پذیرد.
            if (! self::isFetchableUrl($appUrl)) {
                return $this->fallbackIconUrl($appUrl);
            }

            try {
                $html = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; ShahPanel/1.0)'])
                    ->withOptions(['allow_redirects' => self::safeRedirectOptions()])
                    ->get($appUrl)
                    ->body();

                if (preg_match('/property="og:image"\s+content="([^"]+)"/i', $html, $m)) {
                    return html_entity_decode($m[1]);
                }
            } catch (Throwable) {
                // fall through
            }
        }

        if (self::looksLikeDirectImageUrl($appUrl)) {
            return $appUrl;
        }

        return $this->fallbackIconUrl($appUrl);
    }

    protected function fallbackIconUrl(string $appUrl): string
    {
        $host = parse_url($appUrl, PHP_URL_HOST) ?: 'example.com';

        return 'https://www.google.com/s2/favicons?domain='.urlencode((string) $host).'&sz=128';
    }

    protected function isLikelyImagePayload(string $body, string $contentType): bool
    {
        if (strlen($body) < 32) {
            return false;
        }

        // Content-Type را سرور راه دور تعیین می‌کند، پس اعتماد به آن اجازه می‌داد
        // یک SVG/HTML اجراشدنی زیر عنوان image/* ذخیره و بعد روی مبدأ پنل سرو شود.
        // فقط امضای واقعی بایت‌های آغازین پذیرفته می‌شود.
        return $this->detectImageExtension($body) !== null;
    }

    protected function guessExtension(string $contentType, string $url, string $body): string
    {
        $contentType = strtolower($contentType);

        if (str_contains($contentType, 'png')) {
            return 'png';
        }

        if (str_contains($contentType, 'jpeg') || str_contains($contentType, 'jpg')) {
            return 'jpg';
        }

        if (str_contains($contentType, 'webp')) {
            return 'webp';
        }

        if (str_contains($contentType, 'gif')) {
            return 'gif';
        }

        if (str_contains($contentType, 'icon') || str_contains($contentType, 'ico')) {
            return 'ico';
        }

        $fromPath = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        if (in_array($fromPath, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico'], true)) {
            return $fromPath === 'jpeg' ? 'jpg' : $fromPath;
        }

        return $this->detectImageExtension($body) ?? 'png';
    }

    protected function detectImageExtension(string $body): ?string
    {
        if (str_starts_with($body, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }

        if (str_starts_with($body, "\xff\xd8\xff")) {
            return 'jpg';
        }

        if (str_starts_with($body, 'RIFF') && str_contains(substr($body, 0, 16), 'WEBP')) {
            return 'webp';
        }

        if (str_starts_with($body, 'GIF87a') || str_starts_with($body, 'GIF89a')) {
            return 'gif';
        }

        if (str_starts_with($body, "\x00\x00\x01\x00") || str_starts_with($body, "\x00\x00\x02\x00")) {
            return 'ico';
        }

        // SVG عمداً شناسایی نمی‌شود: متنِ اجراشدنی است، نه تصویر.
        return null;
    }

    /**
     * سقف تغییر مسیر به‌همراه بازبینی هر پرش؛ وگرنه یک میزبان عمومی می‌توانست با
     * یک 302 ما را به 127.0.0.1 یا 169.254.169.254 بفرستد و از بررسی اولیه بگذرد.
     *
     * @return array<string, mixed>
     */
    protected static function safeRedirectOptions(): array
    {
        return [
            'max' => 2,
            'strict' => true,
            'referer' => false,
            'protocols' => ['http', 'https'],
            'on_redirect' => static function ($request, $response, $uri): void {
                if (! self::isFetchableUrl((string) $uri)) {
                    throw new RuntimeException('Blocked redirect to a non-public address.');
                }
            },
        ];
    }

    /**
     * فقط http/https و فقط میزبانی که همهٔ نشانی‌های حل‌شده‌اش عمومی باشند.
     */
    protected static function isFetchableUrl(string $url): bool
    {
        $parts = @parse_url(trim($url));

        if (! is_array($parts) || empty($parts['host'])) {
            return false;
        }

        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }

        return self::isPublicHost((string) $parts['host']);
    }

    protected static function isPublicHost(string $host): bool
    {
        $host = trim($host, '[]');

        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host);
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $host) !== 1) {
            return false;
        }

        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }

        if ($addresses === []) {
            return false;
        }

        // اگر حتی یکی از رکوردها داخلی باشد رد می‌کنیم، چون انتخاب نشانی نهایی
        // دست کلاینت HTTP است نه ما.
        foreach ($addresses as $address) {
            if (! self::isPublicIp((string) $address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * همان رویکرد FirewallService::isRoutableIpv4 — پرچم‌های PHP محدودهٔ خصوصی و
     * رزروشده (127/8، 169.254/16، fe80::/10، fc00::/7، ::1 و ::ffff:0:0/96) را رد
     * می‌کنند؛ تنها RFC6598 یعنی 100.64/10 جا می‌افتد و دستی بررسی می‌شود.
     */
    protected static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            if ($long === false) {
                return false;
            }

            return ($long & 0xFFC00000) !== ((100 << 24) | (64 << 16));
        }

        return true;
    }
}
