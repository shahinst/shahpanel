<?php

namespace App\Services\Sanaei;

use App\Models\Server;

/**
 * Resolved panel base URL (origin + optional sub-path + port).
 */
final class SanaeiPanelUrl
{
    public function __construct(
        public readonly string $origin,
        public readonly string $basePath,
        public readonly ?string $apiPrefixOverride = null,
    ) {}

    public static function fromServer(Server $server): self
    {
        $host = trim($server->host);
        $port = (int) $server->port;
        $defaultPort = (int) config('shahpanel.sanaei.default_port', 2053);
        $webBasePath = self::normalizeBasePath((string) ($server->web_base_path ?? ''));

        if (preg_match('#^https?://#i', $host)) {
            $url = self::fromAbsoluteUrl($host, $port > 0 ? $port : $defaultPort);

            if ($webBasePath !== '' && $url->basePath === '') {
                return new self($url->origin, $webBasePath, $url->apiPrefixOverride);
            }

            return $url;
        }

        $usePort = $port > 0 ? $port : $defaultPort;

        return new self("http://{$host}:{$usePort}", $webBasePath);
    }

    /**
     * URL variants to try when connecting.
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
        $basePath = $primary->basePath;
        $override = $primary->apiPrefixOverride;
        $configuredPort = (int) $server->port;
        $defaultPort = (int) config('shahpanel.sanaei.default_port', 2053);

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
            // Many 3x-ui panels on 2053 redirect HTTP→HTTPS (sometimes via HTTP/0.0).
            if ($port === 2053 || $port === 8080 || $port === 2083) {
                $ordered[] = ['https', $port];
                $ordered[] = ['http', $port];
            } elseif ($port === 443) {
                $ordered[] = ['https', $port];
                $ordered[] = ['http', $port];
            } elseif ($port === 80) {
                $ordered[] = ['http', $port];
            } else {
                $ordered[] = ['https', $port];
                $ordered[] = ['http', $port];
            }
        }

        $seen = [];
        $candidates = [];

        foreach ($ordered as [$scheme, $port]) {
            if ($scheme === 'http' && $port === 443) {
                continue;
            }
            if ($scheme === 'https' && $port === 80) {
                continue;
            }

            $origin = self::buildOrigin($scheme, $hostname, $port);
            $key = $origin.$basePath;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $candidates[] = new self($origin, $basePath, $override);
        }

        return $candidates !== [] ? $candidates : [$primary];
    }

    public static function fromAbsoluteUrl(string $url, int $fallbackPort): self
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw new \InvalidArgumentException('آدرس پنل Sanaei نامعتبر است: '.$url);
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $hostname = $parts['host'];
        $path = self::normalizeBasePath($parts['path'] ?? '');
        $port = $parts['port'] ?? null;

        if ($port === null) {
            $defaultSchemePort = $scheme === 'https' ? 443 : 80;
            if ($fallbackPort > 0 && $fallbackPort !== $defaultSchemePort) {
                $port = $fallbackPort;
            }
        }

        $origin = self::buildOrigin($scheme, $hostname, $port !== null ? (int) $port : null);

        $apiPrefixOverride = null;
        if ($path !== '' && str_contains(strtolower($path), 'xui')) {
            $apiPrefixOverride = $path.'/API';
        }

        return new self($origin, $path, $apiPrefixOverride);
    }

    public static function fromLocationHeader(string $location, string $basePath = ''): ?self
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        try {
            $url = self::fromAbsoluteUrl($location, 0);
            if ($basePath !== '' && $url->basePath === '') {
                return new self($url->origin, $basePath, $url->apiPrefixOverride);
            }

            return $url;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function buildOrigin(string $scheme, string $hostname, ?int $port): string
    {
        if ($port === null) {
            return $scheme.'://'.$hostname;
        }

        if ($scheme === 'http' && $port === 80) {
            return 'http://'.$hostname;
        }

        if ($scheme === 'https' && $port === 443) {
            return 'https://'.$hostname;
        }

        return $scheme.'://'.$hostname.':'.$port;
    }

    public static function normalizeBasePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '';
        }

        return '/'.trim($path, '/');
    }

    public function route(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return $this->origin.$this->basePath.$path;
    }

    public function api(string $prefix, string $path): string
    {
        $prefix = '/'.trim($prefix, '/');
        $path = '/'.ltrim($path, '/');

        return $this->origin.$this->basePath.$prefix.$path;
    }

    public function displayAddress(): string
    {
        return $this->origin.$this->basePath;
    }
}
