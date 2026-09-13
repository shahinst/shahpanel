<?php

namespace App\Services\Sanaei;

/**
 * Low-level TCP probe for panels that respond with non-standard HTTP (e.g. HTTP/0.0 redirects).
 */
final class SanaeiHttpProbe
{
    /**
     * @return array{
     *     ok: bool,
     *     http_version?: string,
     *     status?: int,
     *     location?: string,
     *     snippet?: string,
     *     error?: string
     * }
     */
    public static function rawGet(string $origin, string $path = '/', int $timeoutSeconds = 5): array
    {
        $parts = parse_url($origin);

        if ($parts === false || empty($parts['host'])) {
            return ['ok' => false, 'error' => 'آدرس نامعتبر: '.$origin];
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        if ($scheme !== 'http') {
            return ['ok' => false, 'error' => 'raw probe فقط برای HTTP'];
        }

        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? 80);
        $path = '/'.ltrim($path, '/');
        $request = "GET {$path} HTTP/1.1\r\nHost: {$host}".($port !== 80 ? ":{$port}" : '')."\r\nConnection: close\r\n\r\n";

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            return ['ok' => false, 'error' => $errstr !== '' ? $errstr : "اتصال TCP به {$host}:{$port} ناموفق"];
        }

        stream_set_timeout($socket, $timeoutSeconds);
        fwrite($socket, $request);

        $response = '';
        while (! feof($socket)) {
            $chunk = fread($socket, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (strlen($response) > 8192) {
                break;
            }
        }

        fclose($socket);

        if ($response === '') {
            return ['ok' => false, 'error' => 'پاسخ خالی از سرور'];
        }

        $lines = explode("\r\n", $response);
        $statusLine = $lines[0] ?? '';
        $httpVersion = null;
        $status = null;

        if (preg_match('/^(HTTP\/[\d.]+)\s+(\d{3})/', $statusLine, $matches)) {
            $httpVersion = $matches[1];
            $status = (int) $matches[2];
        }

        $location = null;
        foreach ($lines as $line) {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
                break;
            }
        }

        return [
            'ok' => true,
            'http_version' => $httpVersion,
            'status' => $status,
            'location' => $location,
            'snippet' => substr($response, 0, 512),
        ];
    }

    /**
     * Detect HTTP/0.0 redirect to HTTPS (common on some 3x-ui installs).
     *
     * @return array{upgrade_to?: string, reason?: string}
     */
    public static function detectHttpsUpgrade(string $httpOrigin, string $basePath = ''): array
    {
        $raw = self::rawGet($httpOrigin, $basePath !== '' ? $basePath.'/' : '/');

        if (! ($raw['ok'] ?? false)) {
            return ['reason' => $raw['error'] ?? 'raw probe failed'];
        }

        $version = $raw['http_version'] ?? '';
        $location = $raw['location'] ?? '';

        if ($version === 'HTTP/0.0' && $location !== '' && str_starts_with(strtolower($location), 'https://')) {
            return ['upgrade_to' => $location, 'reason' => 'HTTP/0.0 redirect'];
        }

        if (($raw['status'] ?? 0) >= 300 && ($raw['status'] ?? 0) < 400 && $location !== '' && str_starts_with(strtolower($location), 'https://')) {
            return ['upgrade_to' => $location, 'reason' => 'HTTP redirect to HTTPS'];
        }

        return ['reason' => 'no https upgrade needed'];
    }
}
