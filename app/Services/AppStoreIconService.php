<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AppStoreIconService
{
    public const STORED_ICON_PATTERN = '/^[a-f0-9]{40}\.(png|jpe?g|webp|svg|ico)$/i';

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

        try {
            $response = Http::timeout(12)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                ])
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

        if (preg_match('#/(?:storage/)?portal-icons/([a-f0-9]{40}\.(?:png|jpe?g|webp|svg|ico))(?:\?.*)?$#i', $url, $matches)) {
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

        if (preg_match('#/portal-icons/([a-f0-9]{40}\.(?:png|jpe?g|webp|svg|ico))(?:\?.*)?$#i', $publicUrl, $matches)) {
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
            try {
                $html = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; VPNPanel/1.0)'])
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

        $contentType = strtolower($contentType);

        if (str_starts_with($contentType, 'image/')) {
            return true;
        }

        if (str_contains($contentType, 'octet-stream') || str_contains($contentType, 'svg')) {
            return $this->detectImageExtension($body) !== null;
        }

        return $this->detectImageExtension($body) !== null;
    }

    protected function guessExtension(string $contentType, string $url, string $body): string
    {
        $contentType = strtolower($contentType);

        if (str_contains($contentType, 'svg')) {
            return 'svg';
        }

        if (str_contains($contentType, 'png')) {
            return 'png';
        }

        if (str_contains($contentType, 'jpeg') || str_contains($contentType, 'jpg')) {
            return 'jpg';
        }

        if (str_contains($contentType, 'webp')) {
            return 'webp';
        }

        if (str_contains($contentType, 'icon') || str_contains($contentType, 'ico')) {
            return 'ico';
        }

        $fromPath = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        if (in_array($fromPath, ['png', 'jpg', 'jpeg', 'webp', 'svg', 'ico'], true)) {
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

        if (str_starts_with(ltrim($body), '<svg') || str_contains(substr($body, 0, 256), '<svg')) {
            return 'svg';
        }

        return null;
    }
}
