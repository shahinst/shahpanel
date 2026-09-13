<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class DatabaseBackupService
{
    protected string $backupDirectory;

    public function __construct()
    {
        $this->backupDirectory = storage_path('app/backups/database');
    }

    /**
     * @return array{filename: string, path: string, size: int, driver: string}
     */
    public function createBackup(): array
    {
        $this->ensureBackupDirectory();

        $driver = $this->driver();
        $timestamp = now()->format('Y-m-d-His');

        if ($driver === 'sqlite') {
            $filename = "db-backup-{$timestamp}.sqlite";
            $path = $this->backupDirectory.DIRECTORY_SEPARATOR.$filename;
            $database = $this->sqliteDatabasePath();

            if (! is_file($database)) {
                throw new RuntimeException(__('maintenance.backup_database_missing'));
            }

            if (! @copy($database, $path)) {
                throw new RuntimeException(__('maintenance.backup_failed'));
            }
        } elseif ($driver === 'mysql') {
            $filename = "db-backup-{$timestamp}.sql";
            $path = $this->backupDirectory.DIRECTORY_SEPARATOR.$filename;
            $this->runMysqlDump($path);
        } else {
            throw new RuntimeException(__('maintenance.backup_unsupported_driver', ['driver' => $driver]));
        }

        $size = (int) (filesize($path) ?: 0);

        if ($size === 0) {
            @unlink($path);

            throw new RuntimeException(__('maintenance.backup_failed'));
        }

        // A plain dump of this database is well over half a gigabyte; gzip takes
        // it down by roughly an order of magnitude.
        if (Config::get('database.backup.compress', true)) {
            $compressed = $this->compressFile($path);

            if ($compressed !== null) {
                $path = $compressed;
                $filename .= '.gz';
                $size = (int) (filesize($path) ?: 0);
            }
        }

        Setting::setValue('last_database_backup_at', now()->toIso8601String());
        Setting::setValue('last_database_backup_path', $path);

        $this->pruneOldBackups();

        return [
            'filename' => $filename,
            'path' => $path,
            'size' => $size,
            'driver' => $driver,
        ];
    }

    /**
     * @return list<array{filename: string, size: int, modified_at: int, driver: string}>
     */
    public function listBackups(): array
    {
        if (! is_dir($this->backupDirectory)) {
            return [];
        }

        $items = [];

        foreach (scandir($this->backupDirectory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            if (! $this->isAllowedBackupFilename($name)) {
                continue;
            }

            $path = $this->backupDirectory.DIRECTORY_SEPARATOR.$name;

            if (! is_file($path)) {
                continue;
            }

            $items[] = [
                'filename' => $name,
                'size' => (int) filesize($path),
                'modified_at' => (int) filemtime($path),
                'driver' => str_contains($name, '.sqlite') ? 'sqlite' : 'mysql',
            ];
        }

        usort($items, static fn (array $a, array $b): int => $b['modified_at'] <=> $a['modified_at']);

        return $items;
    }

    public function resolveBackupPath(string $filename): string
    {
        $filename = basename($filename);

        if (! $this->isAllowedBackupFilename($filename)) {
            throw new RuntimeException(__('maintenance.backup_invalid_file'));
        }

        $path = $this->backupDirectory.DIRECTORY_SEPARATOR.$filename;

        if (! is_file($path)) {
            throw new RuntimeException(__('maintenance.backup_not_found'));
        }

        return $path;
    }

    public function restoreFromFilename(string $filename): void
    {
        $this->restoreFromPath($this->resolveBackupPath($filename));
    }

    public function restoreFromUploadedFile(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $driver = $this->driver();

        if ($driver === 'sqlite' && ! in_array($extension, ['sqlite', 'db', 'gz'], true)) {
            throw new RuntimeException(__('maintenance.restore_invalid_sqlite'));
        }

        if ($driver === 'mysql' && ! in_array($extension, ['sql', 'gz'], true)) {
            throw new RuntimeException(__('maintenance.restore_invalid_sql'));
        }

        $tempName = 'restore-upload-'.now()->format('YmdHis').'.'.$extension;
        $tempPath = $this->backupDirectory.DIRECTORY_SEPARATOR.$tempName;

        $this->ensureBackupDirectory();
        $file->move($this->backupDirectory, $tempName);

        try {
            $this->restoreFromPath($tempPath);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function restoreFromPath(string $path): void
    {
        if (! is_file($path)) {
            throw new RuntimeException(__('maintenance.backup_not_found'));
        }

        if (str_ends_with(strtolower($path), '.gz')) {
            $plain = $this->decompressToTemp($path);

            try {
                $this->restoreFromPath($plain);
            } finally {
                @unlink($plain);
            }

            return;
        }

        $driver = $this->driver();

        if ($driver === 'sqlite') {
            $database = $this->sqliteDatabasePath();
            DB::disconnect();

            if (! @copy($path, $database)) {
                throw new RuntimeException(__('maintenance.restore_failed'));
            }

            return;
        }

        if ($driver === 'mysql') {
            $this->runMysqlImport($path);

            return;
        }

        throw new RuntimeException(__('maintenance.backup_unsupported_driver', ['driver' => $driver]));
    }

    public function deleteBackup(string $filename): void
    {
        $path = $this->resolveBackupPath($filename);

        if (! @unlink($path)) {
            throw new RuntimeException(__('maintenance.backup_delete_failed'));
        }
    }

    protected function runMysqlDump(string $outputPath): void
    {
        if (Config::get('database.backup.use_cli', false)) {
            try {
                $this->runMysqlDumpViaCli($outputPath);

                if (is_file($outputPath) && filesize($outputPath) > 0) {
                    return;
                }
            } catch (Throwable) {
                @unlink($outputPath);
            }
        }

        $this->runMysqlDumpViaPhp($outputPath);
    }

    protected function runMysqlImport(string $inputPath): void
    {
        DB::disconnect();

        if (Config::get('database.backup.use_cli', false)) {
            try {
                $this->runMysqlImportViaCli($inputPath);

                return;
            } catch (Throwable) {
                // Fall back to PHP import.
            }
        }

        $this->runMysqlImportViaPhp($inputPath);
    }

    protected function runMysqlDumpViaCli(string $outputPath): void
    {
        if (! $this->canRunProcesses()) {
            throw new RuntimeException(__('maintenance.database_command_failed'));
        }

        $connection = $this->connectionConfig();
        $database = (string) $connection['database'];
        $password = (string) ($connection['password'] ?? '');

        $command = [
            $this->mysqlBinary('mysqldump'),
            '--host='.(string) $connection['host'],
            '--port='.(string) ($connection['port'] ?? 3306),
            '--user='.(string) $connection['username'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--set-gtid-purged=OFF',
            $database,
        ];

        $this->runProcess($command, $password, $outputPath);
    }

    protected function runMysqlImportViaCli(string $inputPath): void
    {
        if (! $this->canRunProcesses()) {
            throw new RuntimeException(__('maintenance.database_command_failed'));
        }

        $connection = $this->connectionConfig();
        $database = (string) $connection['database'];
        $password = (string) ($connection['password'] ?? '');

        $command = [
            $this->mysqlBinary('mysql'),
            '--host='.(string) $connection['host'],
            '--port='.(string) ($connection['port'] ?? 3306),
            '--user='.(string) $connection['username'],
            $database,
        ];

        $this->runProcess($command, $password, null, $inputPath);
    }

    /**
     * @param  list<string>  $command
     */
    protected function runProcess(array $command, string $password = '', ?string $outputPath = null, ?string $inputPath = null): void
    {
        $descriptors = [
            0 => $inputPath !== null ? ['file', $inputPath, 'r'] : ['pipe', 'r'],
            1 => $outputPath !== null ? ['file', $outputPath, 'w'] : ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = $this->processEnvironment($password);
        $process = proc_open($command, $descriptors, $pipes, base_path(), $environment);

        if (! is_resource($process)) {
            throw new RuntimeException(__('maintenance.database_command_failed'));
        }

        if ($inputPath === null && isset($pipes[0])) {
            fclose($pipes[0]);
        }

        $stderr = isset($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
        fclose($pipes[2]);

        if ($outputPath === null && isset($pipes[1])) {
            fclose($pipes[1]);
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(__('maintenance.database_command_failed'));
        }

        if ($outputPath !== null && (! is_file($outputPath) || filesize($outputPath) === 0)) {
            throw new RuntimeException(__('maintenance.database_command_failed'));
        }
    }

    protected function runMysqlDumpViaPhp(string $outputPath): void
    {
        $connection = DB::connection();
        $connection->disableQueryLog();

        $database = (string) $connection->getDatabaseName();
        $handle = fopen($outputPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException(__('maintenance.backup_failed'));
        }

        try {
            fwrite($handle, "-- VPN Panel database backup\n");
            fwrite($handle, '-- Generated: '.now()->toDateTimeString()."\n\n");
            fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = $connection->select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
            $tableKey = 'Tables_in_'.$database;

            foreach ($tables as $tableRow) {
                $tableName = (string) $tableRow->{$tableKey};
                $escapedTable = $this->escapeIdentifier($tableName);
                $createRow = $connection->selectOne('SHOW CREATE TABLE '.$escapedTable);
                $createSql = (string) ($createRow->{'Create Table'} ?? '');

                if ($createSql === '') {
                    continue;
                }

                fwrite($handle, 'DROP TABLE IF EXISTS '.$escapedTable.";\n");
                fwrite($handle, $createSql.";\n\n");

                $offset = 0;
                $chunkSize = (int) Config::get('database.backup.chunk_size', 500);

                while (true) {
                    $rows = $connection->select(
                        'SELECT * FROM '.$escapedTable.' LIMIT ? OFFSET ?',
                        [$chunkSize, $offset],
                    );

                    if ($rows === []) {
                        break;
                    }

                    foreach ($rows as $row) {
                        $values = array_map(
                            fn ($value): string => $this->quoteSqlValue($value),
                            array_values((array) $row),
                        );

                        fwrite(
                            $handle,
                            'INSERT INTO '.$escapedTable.' VALUES ('.implode(', ', $values).");\n",
                        );
                    }

                    if (count($rows) < $chunkSize) {
                        break;
                    }

                    $offset += $chunkSize;
                }

                fwrite($handle, "\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($outputPath);

            report($exception);

            throw new RuntimeException(__('maintenance.backup_failed'));
        }

        fclose($handle);
    }

    protected function runMysqlImportViaPhp(string $inputPath): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $connection->disableQueryLog();

        $handle = fopen($inputPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException(__('maintenance.restore_failed'));
        }

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

            $buffer = '';
            $inBlockComment = false;

            while (($line = fgets($handle)) !== false) {
                $trimmed = ltrim($line);

                if ($inBlockComment) {
                    if (str_contains($line, '*/')) {
                        $inBlockComment = false;
                    }

                    continue;
                }

                if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                    continue;
                }

                if (str_starts_with($trimmed, '/*')) {
                    if (! str_contains($trimmed, '*/')) {
                        $inBlockComment = true;
                    }

                    continue;
                }

                $buffer .= $line;

                if (! str_ends_with(rtrim($line), ';')) {
                    continue;
                }

                $statement = trim($buffer);
                $buffer = '';

                if ($statement === '') {
                    continue;
                }

                $pdo->exec($statement);
            }

            $remaining = trim($buffer);

            if ($remaining !== '') {
                $pdo->exec($remaining);
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException(__('maintenance.restore_failed'));
        } finally {
            fclose($handle);
        }
    }

    protected function quoteSqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return DB::connection()->getPdo()->quote($value->format('Y-m-d H:i:s'));
        }

        return DB::connection()->getPdo()->quote((string) $value);
    }

    protected function escapeIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    protected function mysqlBinary(string $binary): string
    {
        $configKey = $binary === 'mysqldump' ? 'database.backup.mysqldump_path' : 'database.backup.mysql_path';
        $configured = Config::get($configKey);

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $binary;
    }

    protected function canRunProcesses(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('proc_open', $disabled, true);
    }

    /**
     * @return array<string, string>|null
     */
    protected function processEnvironment(string $password): ?array
    {
        if ($password === '') {
            return null;
        }

        return array_merge($_ENV, ['MYSQL_PWD' => $password]);
    }

    protected function ensureBackupDirectory(): void
    {
        if (! is_dir($this->backupDirectory)) {
            File::makeDirectory($this->backupDirectory, 0755, true);
        }
    }

    protected function driver(): string
    {
        return (string) Config::get('database.connections.'.$this->connectionName().'.driver');
    }

    /**
     * @return array<string, mixed>
     */
    protected function connectionConfig(): array
    {
        return (array) Config::get('database.connections.'.$this->connectionName(), []);
    }

    protected function connectionName(): string
    {
        return (string) Config::get('database.default');
    }

    protected function sqliteDatabasePath(): string
    {
        return (string) Config::get('database.connections.'.$this->connectionName().'.database');
    }

    protected function isAllowedBackupFilename(string $filename): bool
    {
        return (bool) preg_match('/^db-backup-\d{4}-\d{2}-\d{2}-\d{6}\.(sql|sqlite)(\.gz)?$/', $filename);
    }

    /**
     * Delete every backup beyond the newest `database.backup.retention` files.
     */
    public function pruneOldBackups(): int
    {
        $keep = (int) Config::get('database.backup.retention', 7);

        if ($keep < 1) {
            return 0;
        }

        $removed = 0;

        // listBackups() is already sorted newest first.
        foreach (array_slice($this->listBackups(), $keep) as $backup) {
            if (@unlink($this->backupDirectory.DIRECTORY_SEPARATOR.$backup['filename'])) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Gzip a finished dump in place. Returns the new path, or null if the file
     * could not be compressed -- in which case the caller keeps the plain dump.
     */
    protected function compressFile(string $path): ?string
    {
        $target = $path.'.gz';

        $in = @fopen($path, 'rb');

        if ($in === false) {
            return null;
        }

        $out = @gzopen($target, 'wb6');

        if ($out === false) {
            fclose($in);

            return null;
        }

        // Streamed in chunks: these dumps do not fit in memory.
        while (! feof($in)) {
            $chunk = fread($in, 1048576);

            if ($chunk === false) {
                fclose($in);
                gzclose($out);
                @unlink($target);

                return null;
            }

            if ($chunk !== '' && gzwrite($out, $chunk) === false) {
                fclose($in);
                gzclose($out);
                @unlink($target);

                return null;
            }
        }

        fclose($in);
        gzclose($out);

        if (! is_file($target) || (int) filesize($target) === 0) {
            @unlink($target);

            return null;
        }

        @unlink($path);

        return $target;
    }

    /**
     * Expand a gzipped backup to a sibling temp file for the importer to read.
     */
    protected function decompressToTemp(string $path): string
    {
        $this->ensureBackupDirectory();

        $target = $this->backupDirectory.DIRECTORY_SEPARATOR
            .'restore-'.now()->format('YmdHis').'-'.getmypid().'.sql';

        $in = @gzopen($path, 'rb');

        if ($in === false) {
            throw new RuntimeException(__('maintenance.restore_failed'));
        }

        $out = @fopen($target, 'wb');

        if ($out === false) {
            gzclose($in);

            throw new RuntimeException(__('maintenance.restore_failed'));
        }

        while (! gzeof($in)) {
            $chunk = gzread($in, 1048576);

            if ($chunk === false) {
                gzclose($in);
                fclose($out);
                @unlink($target);

                throw new RuntimeException(__('maintenance.restore_failed'));
            }

            if ($chunk !== '' && fwrite($out, $chunk) === false) {
                gzclose($in);
                fclose($out);
                @unlink($target);

                throw new RuntimeException(__('maintenance.restore_failed'));
            }
        }

        gzclose($in);
        fclose($out);

        return $target;
    }
}
