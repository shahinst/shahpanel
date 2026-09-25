<?php

namespace App\Services\ServerBackup;

use App\Models\ServerBackup;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * Packs a finished backup directory (sections/*.json, metadata.json,
 * manifest.json, and for MikroTik the native .backup file) into one zip so it
 * can travel as a single Telegram document. The collectors deliberately write
 * loose files, so zipping happens here rather than in ServerBackupStorage.
 */
class ServerBackupArchiver
{
    /**
     * @return array{path: string, filename: string, bytes: int}
     */
    public function archive(ServerBackup $backup): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('server_backups.zip_extension_missing'));
        }

        $source = rtrim($backup->absoluteStoragePath(), DIRECTORY_SEPARATOR.'/');

        if ($source === '' || ! is_dir($source)) {
            throw new RuntimeException(__('server_backups.archive_source_missing'));
        }

        $filename = $this->filename($backup);

        // آرشیو کنار پوشهٔ بک‌آپ ساخته می‌شود نه داخلش، وگرنه ZipArchive فایل
        // نیمه‌ساختهٔ خودش را هم به آرشیو اضافه می‌کند.
        $path = dirname($source).DIRECTORY_SEPARATOR.$filename;

        if (is_file($path)) {
            @unlink($path);
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(__('server_backups.archive_failed'));
        }

        $root = basename($source);
        $prefixLength = strlen($source) + 1;

        /** @var SplFileInfo $item */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $item) {
            // نام ورودی‌های زیپ همیشه با «/» است، حتی وقتی مسیر ویندوزی باشد.
            $relative = $root.'/'.str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), $prefixLength));

            if ($item->isDir()) {
                $zip->addEmptyDir($relative);

                continue;
            }

            if (! $item->isFile() || ! $item->isReadable()) {
                continue;
            }

            $zip->addFile($item->getPathname(), $relative);
        }

        $added = $zip->numFiles;
        $closed = $zip->close();

        clearstatcache(true, $path);
        $bytes = is_file($path) ? (int) filesize($path) : 0;

        if (! $closed || $added === 0 || $bytes <= 0) {
            if (is_file($path)) {
                @unlink($path);
            }

            throw new RuntimeException(__('server_backups.archive_empty'));
        }

        return [
            'path' => $path,
            'filename' => $filename,
            'bytes' => $bytes,
        ];
    }

    /** The zip is only a transport wrapper; the backup directory is the artifact. */
    public function discard(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    public function filename(ServerBackup $backup): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9_\-]+/', '-', (string) ($backup->server?->name ?? '')), '-');

        if ($slug === '') {
            $slug = 'server-'.$backup->server_id;
        }

        $stamp = ($backup->created_at ?? now())->format('Y-m-d_His');

        return $slug.'-'.$backup->id.'-'.$stamp.'.zip';
    }
}
