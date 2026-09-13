<?php

namespace App\Services;

use InvalidArgumentException;
use Throwable;

class ApplicationLogService
{
    protected const MAX_READ_BYTES = 2097152;

    protected const MAX_LINES = 2000;

    /**
     * @return list<array{name: string, size: int, modified_at: ?int, readable: bool, writable: bool}>
     */
    public function listFiles(): array
    {
        $directory = storage_path('logs');

        if (! is_dir($directory) || ! is_readable($directory)) {
            return [];
        }

        $files = [];

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            if (! $this->isAllowedFileName($name)) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$name;

            if (! is_file($path)) {
                continue;
            }

            try {
                $size = @filesize($path);
                $mtime = @filemtime($path);

                $files[] = [
                    'name' => $name,
                    'size' => $size !== false ? (int) $size : 0,
                    'modified_at' => $mtime !== false ? (int) $mtime : null,
                    'readable' => is_readable($path),
                    'writable' => is_file($path) && is_writable($path),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        usort(
            $files,
            static fn (array $a, array $b): int => ($b['modified_at'] ?? 0) <=> ($a['modified_at'] ?? 0)
        );

        return $files;
    }

    public function canClear(string $file): bool
    {
        try {
            $path = $this->resolvePath($file);
        } catch (InvalidArgumentException) {
            return false;
        }

        return is_file($path) && is_writable($path);
    }

    /**
     * @return array{
     *     content: string,
     *     file: string,
     *     line_count: int,
     *     truncated: bool,
     *     file_missing: bool,
     *     file_not_readable: bool
     * }
     */
    public function read(
        string $file,
        int $lines = 500,
        ?string $level = null,
        ?string $search = null,
    ): array {
        $lines = max(50, min(self::MAX_LINES, $lines));
        $path = $this->resolvePath($file);

        if (! is_file($path)) {
            return [
                'content' => '',
                'file' => $file,
                'line_count' => 0,
                'truncated' => false,
                'file_missing' => true,
                'file_not_readable' => false,
            ];
        }

        if (! is_readable($path)) {
            return [
                'content' => '',
                'file' => $file,
                'line_count' => 0,
                'truncated' => false,
                'file_missing' => false,
                'file_not_readable' => true,
            ];
        }

        try {
            $raw = $this->readTailBytes($path, self::MAX_READ_BYTES);
            $entries = $this->splitLogEntries($raw);
            $entries = $this->filterEntries($entries, $level, $search);
            $truncated = count($entries) > $lines;
            $entries = array_slice($entries, -$lines);
            $content = implode("\n\n", $entries);

            return [
                'content' => $content,
                'file' => $file,
                'line_count' => count($entries),
                'truncated' => $truncated,
                'file_missing' => false,
                'file_not_readable' => false,
            ];
        } catch (Throwable) {
            return [
                'content' => '',
                'file' => $file,
                'line_count' => 0,
                'truncated' => false,
                'file_missing' => false,
                'file_not_readable' => true,
            ];
        }
    }

    public function resolvePath(string $file): string
    {
        $file = trim($file);

        if ($file === '' || str_contains($file, '..') || str_contains($file, '/') || str_contains($file, '\\')) {
            throw new InvalidArgumentException('Invalid log file name.');
        }

        if (! $this->isAllowedFileName($file)) {
            throw new InvalidArgumentException('Log file is not allowed.');
        }

        return storage_path('logs/'.$file);
    }

    protected function isAllowedFileName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]+\.log$/', $name);
    }

    protected function readTailBytes(string $path, int $maxBytes): string
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            return '';
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            $readFrom = max(0, $size - $maxBytes);
            fseek($handle, $readFrom);
            $content = stream_get_contents($handle) ?: '';

            if ($readFrom > 0) {
                $content = ltrim($content, "\r\n");
            }

            return $content;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<string>
     */
    protected function splitLogEntries(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\r?\n(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  list<string>  $entries
     * @return list<string>
     */
    protected function filterEntries(array $entries, ?string $level, ?string $search): array
    {
        if ($level !== null && $level !== '' && $level !== 'all') {
            $needle = '.'.strtoupper($level).':';
            $entries = array_values(array_filter(
                $entries,
                static fn (string $entry): bool => str_contains($entry, $needle)
            ));
        }

        if ($search !== null && ($search = trim($search)) !== '') {
            $entries = array_values(array_filter(
                $entries,
                static fn (string $entry): bool => stripos($entry, $search) !== false
            ));
        }

        return $entries;
    }

    /**
     * @return array{cleared: bool, error: ?string}
     */
    public function clear(string $file): array
    {
        $path = $this->resolvePath($file);

        if (! is_file($path)) {
            return ['cleared' => false, 'error' => 'missing'];
        }

        if (! is_writable($path)) {
            return ['cleared' => false, 'error' => 'not_writable'];
        }

        try {
            $handle = @fopen($path, 'r+');

            if ($handle === false) {
                return ['cleared' => false, 'error' => 'not_writable'];
            }

            ftruncate($handle, 0);
            fflush($handle);
            fclose($handle);

            return ['cleared' => true, 'error' => null];
        } catch (Throwable) {
            return ['cleared' => false, 'error' => 'failed'];
        }
    }
}
