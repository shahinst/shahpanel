<?php

namespace App\Services\ServerBackup;

use App\Models\ServerBackup;
use Illuminate\Support\Facades\File;

class ServerBackupStorage
{
    public function baseRelativePath(int $serverId, string $folderName): string
    {
        return 'backups/servers/'.$serverId.'/'.$folderName;
    }

    public function makeBackupDirectory(int $serverId): array
    {
        $folderName = now()->format('Y-m-d_His');
        $relative = $this->baseRelativePath($serverId, $folderName);
        $absolute = storage_path('app/'.$relative);
        $sectionsDir = $absolute.DIRECTORY_SEPARATOR.'sections';

        File::ensureDirectoryExists($sectionsDir, 0750, true);

        return [
            'folder' => $folderName,
            'relative' => $relative,
            'absolute' => $absolute,
            'sections_dir' => $sectionsDir,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function writeJson(string $absolutePath, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new \RuntimeException('JSON encoding failed for backup file.');
        }

        File::put($absolutePath, $encoded);
    }

    /**
     * @param  array<string, mixed>  $sections
     * @param  list<array{section: string, message: string}>  $errors
     * @return array<string, array{file: string, count: int|null, bytes: int}>
     */
    public function saveSections(string $sectionsDir, array $sections, array $errors): array
    {
        $manifestSections = [];

        foreach ($sections as $name => $payload) {
            $safeName = $this->sanitizeSectionName((string) $name);
            $filename = $safeName.'.json';
            $absolute = $sectionsDir.DIRECTORY_SEPARATOR.$filename;
            $this->writeJson($absolute, [
                'section' => $safeName,
                'data' => $payload,
            ]);

            $manifestSections[$safeName] = [
                'file' => 'sections/'.$filename,
                'count' => $this->estimateCount($payload),
                'bytes' => is_file($absolute) ? (int) filesize($absolute) : 0,
            ];
        }

        if ($errors !== []) {
            $this->writeJson($sectionsDir.DIRECTORY_SEPARATOR.'_errors.json', [
                'section' => '_errors',
                'data' => $errors,
            ]);
            $manifestSections['_errors'] = [
                'file' => 'sections/_errors.json',
                'count' => count($errors),
                'bytes' => is_file($sectionsDir.DIRECTORY_SEPARATOR.'_errors.json')
                    ? (int) filesize($sectionsDir.DIRECTORY_SEPARATOR.'_errors.json')
                    : 0,
            ];
        }

        return $manifestSections;
    }

    /**
     * @param  list<array{name: string, label: string, file: string, bytes: int, type?: string}>  $files
     * @return array<string, array{file: string, label: string, count: null, bytes: int, type: string}>
     */
    public function registerFiles(string $absoluteBackupDir, array $files): array
    {
        unset($absoluteBackupDir);

        $manifestFiles = [];

        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            $name = $this->sanitizeSectionName((string) ($file['name'] ?? 'file'));
            $relativeFile = (string) ($file['file'] ?? '');

            if ($relativeFile === '' || str_contains($relativeFile, '..')) {
                continue;
            }

            $manifestFiles[$name] = [
                'file' => $relativeFile,
                'label' => (string) ($file['label'] ?? $relativeFile),
                'count' => null,
                'bytes' => (int) ($file['bytes'] ?? 0),
                // هر کالکتورِ فایل‌محور نوع خودش را اعلام می‌کند (mikrotik_backup،
                // sanaei_database)، وگرنه اسم میکروتیک روی فایل بقیه می‌نشست.
                'type' => (string) ($file['type'] ?? 'file'),
            ];
        }

        return $manifestFiles;
    }

    public function sanitizeSectionName(string $name): string
    {
        $safe = preg_replace('/[^a-z0-9_\-]/i', '_', trim($name)) ?? '';

        return $safe !== '' ? $safe : 'section';
    }

    public function readSection(ServerBackup $backup, string $section): ?array
    {
        $safe = $this->sanitizeSectionName($section);
        $manifest = $backup->manifest ?? [];
        $sections = $manifest['sections'] ?? [];

        if (! is_array($sections) || ! isset($sections[$safe])) {
            return null;
        }

        $relativeFile = (string) ($sections[$safe]['file'] ?? '');
        if ($relativeFile === '' || str_contains($relativeFile, '..')) {
            return null;
        }

        $path = $backup->absoluteStoragePath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readMetadata(ServerBackup $backup): ?array
    {
        $path = $backup->absoluteStoragePath().DIRECTORY_SEPARATOR.'metadata.json';

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function estimateCount(mixed $payload): ?int
    {
        if (! is_array($payload)) {
            return null;
        }

        if (array_is_list($payload)) {
            return count($payload);
        }

        foreach (['users', 'total', 'count'] as $key) {
            if ($key === 'users' && is_array($payload['users'] ?? null)) {
                return count($payload['users']);
            }

            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (int) $payload[$key];
            }
        }

        return count($payload);
    }
}
