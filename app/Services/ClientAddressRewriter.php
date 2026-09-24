<?php

namespace App\Services;

use App\Models\Server;

/**
 * نشانی مدیریتی سرور را در هر چیزی که به دست کاربر می‌رسد با نشانی کاربرمحور
 * (servers.client_host، یعنی تونل/رله) جایگزین می‌کند.
 *
 * چرا یک سرویس مشترک: بخشی از کانفیگ‌ها را خودمان می‌سازیم و بخشی را خودِ پنل
 * تولید کرده و ما فقط تحویل می‌دهیم (کش اشتراک). دومی را نمی‌توان سر منشأ درست
 * کرد، پس هر دو مسیر از همین یک بازنویس عبور می‌کنند تا قانون یکسان بماند.
 *
 * قانون سختِ این کلاس: هر شکلی از شکستِ تحلیل، رشتهٔ ورودی را دست‌نخورده
 * برمی‌گرداند. کانفیگ نیمه‌بازنویسی‌شده از باگ فعلی بدتر است — کاربر یک لینک
 * مرده می‌گیرد و هیچ راهی برای تشخیصش ندارد.
 *
 * عمداً فقط «میزبان» (و در صورت تنظیم client_port، پورت) عوض می‌شود؛
 * پارامترهای sni/host/path دست‌نخورده می‌مانند، چون نام TLS و هدر Host بخشی از
 * هویت سرویس روی سرور مقصدند و عوض‌کردنشان دست‌دادن TLS را می‌شکند.
 */
class ClientAddressRewriter
{
    /**
     * یک خط کانفیگ را به نشانی کاربرمحور برمی‌گرداند. طرح‌های ناشناس و سرورهای
     * بدون client_host بدون تغییر رد می‌شوند.
     */
    public function rewriteConfigUri(string $uri, Server $server): string
    {
        $host = $this->clientHost($server);

        if ($host === '') {
            return $uri;
        }

        $line = trim($uri);

        if ($line === '' || ! str_contains($line, '://')) {
            return $uri;
        }

        $scheme = strtolower((string) strstr($line, '://', true));
        $port = $server->clientPort();

        $rewritten = match ($scheme) {
            'vmess' => $this->rewriteVmess($line, $host, $port),
            // ss:// را هم پوشش می‌دهیم: شاه‌پنل تولیدش نمی‌کند ولی 3x-ui می‌تواند
            // و آن خط‌ها از کش اشتراک بیرون می‌آیند.
            'vless', 'trojan', 'ss' => $this->replaceAuthorityHost($line, $host, $port, true),
            default => '',
        };

        return $rewritten !== '' ? $rewritten : $uri;
    }

    /**
     * @param  list<string>  $uris
     * @return list<string>
     */
    public function rewriteConfigUris(array $uris, Server $server): array
    {
        if ($this->clientHost($server) === '') {
            return $uris;
        }

        return array_values(array_map(
            fn (string $uri): string => $this->rewriteConfigUri($uri, $server),
            $uris
        ));
    }

    /**
     * میزبانِ یک نشانی معمولی (لینک اشتراک) را عوض می‌کند و طرح، مسیر، کوئری و
     * فرگمنت را دست نمی‌زند.
     *
     * پورت عمداً حفظ می‌شود: client_port پورتِ دادهٔ کاربر است، در حالی که لینک
     * اشتراک روی پورت وبِ پنل سِرو می‌شود؛ تحمیل client_port این‌جا یک نشانی
     * مرده می‌سازد.
     */
    public function rewriteUrlHost(string $url, Server $server): string
    {
        $host = $this->clientHost($server);

        if ($host === '' || ! str_contains($url, '://')) {
            return $url;
        }

        $rewritten = $this->replaceAuthorityHost(trim($url), $host, null, false);

        return $rewritten !== '' ? $rewritten : $url;
    }

    /** نشانی کاربرمحورِ تنظیم‌شده، یا رشتهٔ خالی یعنی «کاری نکن». */
    protected function clientHost(Server $server): string
    {
        return $server->hasClientHost() ? $server->clientHost() : '';
    }

    /**
     * جایگزینی میزبان در بخش authority؛ همه‌چیز پیش و پس از آن بایت‌به‌بایت حفظ
     * می‌شود. رشتهٔ خالی یعنی «نشد، ورودی را نگه دار».
     */
    protected function replaceAuthorityHost(string $uri, string $host, ?int $forcePort, bool $requireUserInfo): string
    {
        $separator = strpos($uri, '://');

        if ($separator === false) {
            return '';
        }

        $start = $separator + 3;
        $end = strlen($uri);

        foreach (['/', '?', '#'] as $marker) {
            $position = strpos($uri, $marker, $start);

            if ($position !== false && $position < $end) {
                $end = $position;
            }
        }

        $authority = substr($uri, $start, $end - $start);

        if ($authority === '') {
            return '';
        }

        $at = strrpos($authority, '@');

        if ($at === false && $requireUserInfo) {
            // در کانفیگ بدون userinfo نمی‌توان مطمئن شد کدام بخش میزبان است —
            // مثلاً ss:// قدیمی که کل «method:pass@host:port» یک base64 است.
            return '';
        }

        $userInfo = $at === false ? '' : substr($authority, 0, $at + 1);
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);

        $port = $forcePort ?? $this->portFromHostPort($hostPort);
        $replacement = $this->formatHostPort($host, $port);

        if ($replacement === '') {
            return '';
        }

        return substr($uri, 0, $start).$userInfo.$replacement.substr($uri, $end);
    }

    /**
     * payload یک vmess را باز می‌کند، add (و در صورت تنظیم port) را عوض می‌کند و
     * با همان پرچم‌های json_encode که سازندهٔ کانفیگ خودمان استفاده می‌کند
     * دوباره می‌بندد تا خروجی دو مسیر یکسان بماند.
     */
    protected function rewriteVmess(string $uri, string $host, ?int $port): string
    {
        $payload = substr($uri, strlen('vmess://'));

        // بعضی پنل‌ها remark را بعد از «#» می‌گذارند؛ آن تکه جزء payload نیست.
        $fragment = '';
        $hash = strpos($payload, '#');

        if ($hash !== false) {
            $fragment = substr($payload, $hash);
            $payload = substr($payload, 0, $hash);
        }

        $decoded = $this->base64Decode($payload);

        if ($decoded === null) {
            return '';
        }

        $config = json_decode($decoded, true);

        if (! is_array($config) || ! array_key_exists('add', $config)) {
            return '';
        }

        $config['add'] = $host;

        if ($port !== null) {
            // نوع مقدار قبلی حفظ می‌شود (3x-ui رشته می‌دهد و بعضی کلاینت‌ها
            // سخت‌گیرند) تا تنها تفاوت payload خودِ عدد باشد.
            $config['port'] = is_int($config['port'] ?? null) ? $port : (string) $port;
        }

        $encoded = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            return '';
        }

        return 'vmess://'.base64_encode($encoded).$fragment;
    }

    /** base64 استاندارد و base64url، با یا بدون padding. null یعنی نشد. */
    protected function base64Decode(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = strtr($value, '-_', '+/');
        $remainder = strlen($normalized) % 4;

        if ($remainder > 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalized, true);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    protected function portFromHostPort(string $hostPort): ?int
    {
        if (preg_match('/^\[[^\]]*\](?::(\d{1,5}))?$/', $hostPort, $matches) === 1) {
            return isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : null;
        }

        if (preg_match('/^[^:]+:(\d{1,5})$/', $hostPort, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    protected function formatHostPort(string $host, ?int $port): string
    {
        if ($host === '') {
            return '';
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '['.$host.']';
        }

        return $port !== null ? $host.':'.$port : $host;
    }
}
