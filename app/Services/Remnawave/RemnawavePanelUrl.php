<?php

namespace App\Services\Remnawave;

use App\Models\Server;

/**
 * Resolved Remnawave panel origin. REST API lives at {origin}{basePath}/api/…
 *
 * `web_base_path` is treated as an optional secret prefix (e.g. when the panel
 * sits behind Caddy on a hidden path) inserted before the `/api` segment.
 */
final class RemnawavePanelUrl
{
    public function __construct(
        public readonly string $origin,
        public readonly string $basePath = '',
        public readonly string $apiSegment = '/api',
    ) {}

    public static function fromServer(Server $server): self
    {
        $host = trim((string) $server->host);
        $port = (int) $server->port;
        $defaultPort = (int) config('vpnpanel.remnawave.default_port', 443);
        $basePath = self::normalizeBasePath((string) ($server->web_base_path ?? ''));

        if (preg_match('#^https?://#i', $host)) {
            $parsed = parse_url($host);
            if ($parsed !== false && ! empty($parsed['host'])) {
                $scheme = $parsed['scheme'] ?? 'https';
                $originPort = isset($parsed['port']) ? (int) $parsed['port'] : null;
                $usePort = $port > 0 ? $port : ($originPort ?? $defaultPort);
                $origin = $scheme.'://'.$parsed['host'];
                if (! self::isDefaultPort($scheme, $usePort)) {
                    $origin .= ':'.$usePort;
                }

                $pathFromUrl = self::stripApiSuffixFromPath((string) ($parsed['path'] ?? ''));

                return new self($origin, $basePath !== '' ? $basePath : $pathFromUrl);
            }

            return new self(rtrim($host, '/'), $basePath);
        }

        $usePort = $port > 0 ? $port : $defaultPort;
        $origin = "https://{$host}";

        // Same rule the URL branch above already applies: a default port is
        // implied by the scheme, and spelling it out produces a non-canonical
        // origin (https://host:443) that some panels reject on redirect.
        if (! self::isDefaultPort('https', $usePort)) {
            $origin .= ':'.$usePort;
        }

        return new self($origin, $basePath);
    }

    /**
     * URL variants to try when probing the panel (HTTPS first on 443).
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
        $defaultPort = (int) config('vpnpanel.remnawave.default_port', 443);

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
            $out[] = new self($origin, $primary->basePath, $primary->apiSegment);
        }

        return $out !== [] ? $out : [$primary];
    }

    public function api(string $relativePath): string
    {
        $path = ltrim($relativePath, '/');

        return $this->apiBaseUrl().'/'.$path;
    }

    /** آدرس پنل برای نمایش به ادمین (بدون مسیر API). */
    public function displayAddress(): string
    {
        return rtrim($this->origin, '/').$this->basePath;
    }

    public function apiBaseUrl(): string
    {
        return rtrim($this->origin, '/').$this->basePath.$this->apiSegment;
    }

    protected static function normalizeBasePath(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '' : '/'.$path;
    }

    /** مسیر `/api` در آدرس پنل نباید قبل از segment خودکار `/api` تکرار شود. */
    protected static function stripApiSuffixFromPath(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '' || strtolower($path) === 'api') {
            return '';
        }

        if (preg_match('#(?:^|/)api$#i', $path)) {
            $path = preg_replace('#(?:^|/)api$#i', '', $path) ?? $path;
            $path = trim($path, '/');
        }

        return self::normalizeBasePath($path);
    }

    protected static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    }
}
