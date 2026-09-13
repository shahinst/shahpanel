<?php

namespace App\Support;

use RuntimeException;

/**
 * Loads phpseclib3 without relying on shell functions or proc_open.
 */
final class PhpSecLibLoader
{
    private static bool $registered = false;

    public static function sftpClass(): string
    {
        self::ensureAvailable();

        return \phpseclib3\Net\SFTP::class;
    }

    public static function ensureAvailable(): void
    {
        if (\class_exists(\phpseclib3\Net\SFTP::class)) {
            return;
        }

        self::registerFallbackAutoloader();

        if (\class_exists(\phpseclib3\Net\SFTP::class)) {
            return;
        }

        throw new RuntimeException(self::diagnosticMessage());
    }

    private static function registerFallbackAutoloader(): void
    {
        if (self::$registered) {
            return;
        }

        $root = self::libraryRoot();

        if ($root === null) {
            return;
        }

        spl_autoload_register(static function (string $class) use ($root): void {
            if (! str_starts_with($class, 'phpseclib3\\')) {
                return;
            }

            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 11));
            $path = $root.DIRECTORY_SEPARATOR.$relative.'.php';

            if (is_file($path)) {
                require_once $path;
            }
        }, prepend: true);

        self::$registered = true;
    }

    private static function libraryRoot(): ?string
    {
        foreach (self::libraryRootCandidates() as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function libraryRootCandidates(): array
    {
        return [
            base_path('vendor/phpseclib/phpseclib/phpseclib'),
            base_path('vendor/phpseclib/phpseclib'),
        ];
    }

    private static function diagnosticMessage(): string
    {
        $root = self::libraryRoot();
        $sftpFile = $root !== null
            ? $root.DIRECTORY_SEPARATOR.'Net'.DIRECTORY_SEPARATOR.'SFTP.php'
            : null;

        if ($sftpFile !== null && is_file($sftpFile)) {
            return 'phpseclib files exist but could not be loaded. Run: composer dump-autoload -o';
        }

        return 'phpseclib is missing from vendor/. Run: composer install --no-dev';
    }
}
