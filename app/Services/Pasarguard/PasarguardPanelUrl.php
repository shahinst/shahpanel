<?php

namespace App\Services\Pasarguard;

use App\Models\Server;

/**
 * Resolved PasarGuard panel origin. REST API lives at {origin}/api/…
 * (not under /dashboard — that path is only the web UI).
 */
final class PasarguardPanelUrl
{
    public function __construct(
        public readonly string $origin,
        public readonly string $apiPrefix = '/api',
    ) {}

    public static function fromServer(Server $server): self
    {
        $host = trim($server->host);
        $port = (int) $server->port;
        $defaultPort = (int) config('vpnpanel.pasarguard.default_port', 443);
        $webBasePath = self::normalizeBasePath((string) ($server->web_base_path ?? ''));

        if (preg_match('#^https?://#i', $host)) {
            $url = self::fromAbsoluteUrl($host, $port > 0 ? $port : $defaultPort);

            if ($webBasePath !== '' && $url->origin === rtrim($host, '/')) {
                // User pasted full dashboard URL — strip UI sub-path for API origin.
                $parsed = parse_url($host);
                if ($parsed !== false && ! empty($parsed['host'])) {
                    $scheme = $parsed['scheme'] ?? 'https';
                    $originPort = isset($parsed['port']) ? (int) $parsed['port'] : null;
                    $usePort = $port > 0 ? $port : ($originPort ?? $defaultPort);
                    $origin = $scheme.'://'.$parsed['host'];
                    if (! self::isDefaultPort($scheme, $usePort)) {
                        $origin .= ':'.$usePort;
                    }

                    return new self($origin);
                }
            }

            return $url;
        }

        $usePort = $port > 0 ? $port : $defaultPort;

        return new self("https://{$host}:{$usePort}");
    }

    /**
     * URL variants to try when connecting (HTTPS first on 443).
     *
     * @return list<self>
     */
    public static function candidatesFromServer(Server $server): array
    {
        $primary = self::fromServer($server);
        $parsed = parse_url($primary->origin);

        if ($parsed === false || empty($parsed['host'])) {
            return [$primary];
        }

        $hostname = $parsed['host'];
        $configuredPort = (int) $server->port;
        $defaultPort = (int) config('vpnpanel.pasarguard.default_port', 443);

        $ports = array_values(array_unique(array_filter([
            $parsed['port'] ?? null,
            $configuredPort > 0 ? $configuredPort : null,
            $defaultPort,
            443,
            80,
        ], fn ($p) => $p !== null && (int) $p > 0)));

        $ordered = [];
        foreach ($ports as $port) {
            $port = (int) $port;
            if ($port === 443) {
                $ordered[] = ['https', $port];
                $ordered[] = ['http', $port];
            } elseif ($port === 80) {
                $ordered[] = ['http', $port];
                $ordered[] = ['https', $port];
            } else {
                $ordered[] = ['https', $port];
                $ordered[] = ['http', $port];
            }
        }

        $seen = [];
        $out = [];
        foreach ($ordered as [$scheme, $port]) {
            $origin = $scheme.'://'.$hostname;
            if (! self::isDefaultPort($scheme, $port)) {
                $origin .= ':'.$port;
            }
            if (isset($seen[$origin])) {
                continue;
            }
            $seen[$origin] = true;
            $out[] = new self($origin, $primary->apiPrefix);
        }

        return $out !== [] ? $out : [$primary];
    }

    public function api(string $relativePath): string
    {
        $path = ltrim($relativePath, '/');

        return rtrim($this->origin, '/').rtrim($this->apiPrefix, '/').'/'.$path;
    }

    /** آدرس پنل برای نمایش به ادمین (بدون مسیر API). */
    public function displayAddress(): string
    {
        return rtrim($this->origin, '/');
    }

    public function apiBaseUrl(): string
    {
        return rtrim($this->origin, '/').$this->apiPrefix;
    }

    protected static function fromAbsoluteUrl(string $url, int $port): self
    {
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            return new self(rtrim($url, '/'));
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $usePort = isset($parsed['port']) ? (int) $parsed['port'] : $port;
        $origin = $scheme.'://'.$host;
        if (! self::isDefaultPort($scheme, $usePort)) {
            $origin .= ':'.$usePort;
        }

        return new self($origin);
    }

    protected static function normalizeBasePath(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '' : '/'.$path;
    }

    protected static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    }
}
