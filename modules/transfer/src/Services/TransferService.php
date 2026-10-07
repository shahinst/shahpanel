<?php

namespace Modules\Transfer\Services;

use App\Models\Module;
use App\Services\DatabaseBackupService;
use App\Support\PortalPaths;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Replaces this panel with an uploaded backup of another one.
 *
 * Uploads arrive in chunks (nginx allows 32 MB and PHP 2 MB per request, while
 * a backup zip from Telegram can reach 50 MB), then a detached
 * `transfer:restore` run does the work while the page polls a status file:
 * one restore takes far longer than any web request may.
 *
 * The new server keeps its own .env, so its APP_KEY differs from the one that
 * encrypted the backup's secrets. Backups never carry that key (they travel
 * through Telegram), so the owner types in the old one, which is proven
 * against a secret in the dump before anything else; everything encrypted is
 * then moved to this server's key.
 *
 * Nothing is touched until the backup has been read and its key proven; from
 * the moment the tables are dropped, any failure puts back a safety backup of
 * this panel taken just before.
 */
class TransferService
{
    public const MAX_BYTES = 2 * 1024 * 1024 * 1024;

    public const STEPS = ['extract', 'check', 'backup', 'import', 'reencrypt', 'migrate'];

    public function __construct(protected DatabaseBackupService $backups) {}

    public function dir(string $job): string
    {
        return storage_path('app/panel-transfer/'.$job);
    }

    /** Request-size limits leave room for the other form fields. */
    public function chunkBytes(): int
    {
        $limits = array_filter([
            ini_parse_quantity((string) ini_get('upload_max_filesize')),
            ini_parse_quantity((string) ini_get('post_max_size')) - 65536,
        ], static fn (int $bytes): bool => $bytes > 0);

        return max(262144, min(8388608, $limits === [] ? 8388608 : min($limits)));
    }

    /** Chunks must arrive in order: $offset is where this one starts. */
    public function appendChunk(string $job, int $offset, UploadedFile $chunk): int
    {
        $dir = $this->dir($job);
        $path = $dir.'/upload.bin';

        if (is_file($dir.'/status.json')) {
            throw new RuntimeException(__('transfer::transfer.already_started'));
        }

        if ($offset === 0) {
            $this->pruneOldJobs();
            File::ensureDirectoryExists($dir, 0700);
            @unlink($path);
        }

        clearstatcache(true, $path);
        $size = is_file($path) ? (int) filesize($path) : 0;

        if (! is_dir($dir) || $size !== $offset) {
            throw new RuntimeException(__('transfer::transfer.chunk_out_of_order'));
        }

        if ($size + (int) $chunk->getSize() > self::MAX_BYTES) {
            throw new RuntimeException(__('transfer::transfer.too_large'));
        }

        file_put_contents($path, (string) file_get_contents($chunk->getRealPath()), FILE_APPEND | LOCK_EX);
        clearstatcache(true, $path);

        return (int) filesize($path);
    }

    public function start(string $job, string $appKey): void
    {
        $dir = $this->dir($job);

        if (! is_file($dir.'/upload.bin') || (int) filesize($dir.'/upload.bin') === 0) {
            throw new RuntimeException(__('transfer::transfer.nothing_uploaded'));
        }

        if (is_file($dir.'/status.json')) {
            throw new RuntimeException(__('transfer::transfer.already_started'));
        }

        file_put_contents($dir.'/job.json', json_encode(['key' => trim($appKey)]));
        @chmod($dir.'/job.json', 0600);
        $this->writeStatus($job, ['state' => 'queued', 'step' => null]);
        $this->launch($job);
    }

    /** Detached, so it outlives the request that started it. */
    protected function launch(string $job): void
    {
        $php = PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $php = is_executable($php) ? $php : PHP_BINDIR.'/php';

        if (! function_exists('exec')) {
            $this->writeStatus($job, ['state' => 'failed', 'message' => __('transfer::transfer.cannot_launch'), 'outcome' => 'untouched']);

            return;
        }

        exec(sprintf(
            'cd %s && setsid nohup %s artisan transfer:restore %s > %s 2>&1 < /dev/null &',
            escapeshellarg(base_path()),
            escapeshellarg($php),
            escapeshellarg($job),
            escapeshellarg($this->dir($job).'/run.log'),
        ));
    }

    public function run(string $job): void
    {
        $dir = $this->dir($job);
        File::ensureDirectoryExists(dirname($dir), 0700);
        $lock = fopen(dirname($dir).'/.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->writeStatus($job, ['state' => 'failed', 'message' => __('transfer::transfer.busy'), 'outcome' => 'untouched']);

            return;
        }

        $options = json_decode((string) @file_get_contents($dir.'/job.json'), true) ?: [];
        // The typed-in key stays on disk no longer than it takes to read it.
        @unlink($dir.'/job.json');

        $down = false;
        $wiped = false;
        $safety = null;

        try {
            $this->step($job, 'extract');

            if (DB::connection()->getDriverName() !== 'mysql') {
                throw new RuntimeException(__('transfer::transfer.mysql_only'));
            }

            $sql = $this->extract($dir.'/upload.bin', $dir.'/dump.sql');
            @unlink($dir.'/upload.bin');

            $this->step($job, 'check');
            $dump = $this->inspect($sql);
            $fromKey = $this->sourceKey($dump, (string) ($options['key'] ?? ''));

            $this->step($job, 'backup');
            Artisan::call('down', ['--retry' => 30]);
            $down = true;
            $safety = $this->backups->createBackup()['path'];

            $this->step($job, 'import');
            $wiped = true;
            Schema::dropAllTables();
            $this->backups->restoreFromPath($sql);

            $this->step($job, 'reencrypt');
            $toKey = (string) config('app.key');

            if ($fromKey !== null && $fromKey !== $toKey) {
                (new Reencryptor($this->encrypter($fromKey), $this->encrypter($toKey), $toKey))->run();
            }

            $this->step($job, 'migrate');
            $this->upgrade();
            $wiped = false;

            $this->writeStatus($job, [
                'state' => 'done',
                'step' => null,
                'source_version' => $dump['version'],
                'login_url' => route('login'),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $outcome = ! $wiped ? 'untouched' : ($this->rollback((string) $safety) ? 'rolled_back' : 'rollback_failed');

            $this->writeStatus($job, [
                'state' => 'failed',
                'message' => $exception->getMessage(),
                'outcome' => $outcome,
                'safety_backup' => $safety !== null ? basename($safety) : null,
            ]);
        } finally {
            if ($down) {
                Artisan::call('up');
            }

            @unlink($dir.'/upload.bin');
            @unlink($dir.'/dump.sql');
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed>|null */
    public function status(string $job): ?array
    {
        $status = json_decode((string) @file_get_contents($this->dir($job).'/status.json'), true);

        return is_array($status) ? $status : null;
    }

    /**
     * Accepts the zip Telegram receives, the .sql.gz kept on the old server, or
     * a plain .sql, told apart by their first bytes.
     */
    protected function extract(string $upload, string $target): string
    {
        $magic = (string) @file_get_contents($upload, false, null, 0, 4);

        if (str_starts_with($magic, "PK\x03\x04")) {
            $zip = new ZipArchive;

            if ($zip->open($upload) !== true) {
                throw new RuntimeException(__('transfer::transfer.not_a_backup'));
            }

            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);

                    if (preg_match('/\.sql(\.gz)?$/i', $name)) {
                        $packed = str_ends_with(strtolower($name), '.gz') ? $target.'.gz' : $target;
                        $this->copyStream($zip->getStream($name), $packed);

                        return $packed === $target ? $target : $this->gunzip($packed, $target);
                    }
                }
            } finally {
                $zip->close();
            }

            throw new RuntimeException(__('transfer::transfer.not_a_backup'));
        }

        if (str_starts_with($magic, "\x1f\x8b")) {
            return $this->gunzip($upload, $target);
        }

        rename($upload, $target);

        return $target;
    }

    /**
     * Reads the dump once: its header, a sample secret to prove the key
     * against, and enough table names to know it is a ShahPanel backup.
     *
     * @return array{version: ?string, sample: ?string}
     */
    protected function inspect(string $sql): array
    {
        $info = ['version' => null, 'sample' => null];
        $tables = [];
        $handle = fopen($sql, 'rb');
        $line = 0;

        while (($text = fgets($handle)) !== false) {
            if (++$line <= 30 && preg_match('/^-- ShahPanel-Version: (\S+)/', $text, $match)) {
                $info['version'] = $match[1];
            }

            if (preg_match('/^CREATE TABLE `([^`]+)`/', $text, $match)) {
                $tables[$match[1]] = true;
            }

            // Only a whole quoted value: inside JSON the dump escapes slashes.
            if ($info['sample'] === null && preg_match("/'(eyJpdiI6[A-Za-z0-9+\\/=]+)'/", $text, $match)) {
                $info['sample'] = $match[1];
            }
        }

        fclose($handle);

        if (! isset($tables['users'], $tables['settings'], $tables['migrations'])) {
            throw new RuntimeException(__('transfer::transfer.not_a_backup'));
        }

        $here = trim((string) @file_get_contents(base_path('VERSION')));

        if ($info['version'] !== null && $here !== '' && version_compare($info['version'], $here, '>')) {
            throw new RuntimeException(__('transfer::transfer.newer_backup', ['backup' => $info['version'], 'panel' => $here]));
        }

        return $info;
    }

    /** The key that encrypted the backup, or null when it holds no secrets. */
    protected function sourceKey(array $dump, string $typed): ?string
    {
        $key = $typed !== '' ? $typed : null;

        if ($key === null) {
            if ($dump['sample'] !== null) {
                throw new RuntimeException(__('transfer::transfer.key_required'));
            }

            return null;
        }

        if ($dump['sample'] !== null) {
            try {
                $this->encrypter($key)->decrypt($dump['sample'], false);
            } catch (DecryptException) {
                throw new RuntimeException(__('transfer::transfer.key_mismatch'));
            }
        }

        return $key;
    }

    protected function encrypter(string $key): Encrypter
    {
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $cipher = (string) config('app.cipher', 'AES-256-CBC');

        if (! is_string($raw) || ! Encrypter::supported($raw, $cipher)) {
            throw new RuntimeException(__('transfer::transfer.key_invalid'));
        }

        return new Encrypter($raw, $cipher);
    }

    /** Brings an older backup up to this panel's code and modules. */
    protected function upgrade(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        // The restored modules table predates this tool; keep it switched on
        // so the page that started the transfer can show how it ended.
        DB::table('modules')->where('slug', 'transfer')
            ->update(['status' => Module::STATUS_ACTIVE, 'activated_at' => now()]);

        Artisan::call('module:sync');
        Artisan::call('optimize:clear');
        PortalPaths::clearCache();
    }

    protected function rollback(string $safety): bool
    {
        try {
            Schema::dropAllTables();
            $this->backups->restoreFromPath($safety);
            Artisan::call('optimize:clear');

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    protected function step(string $job, string $step): void
    {
        $this->writeStatus($job, ['state' => 'running', 'step' => $step]);
    }

    protected function writeStatus(string $job, array $values): void
    {
        $path = $this->dir($job).'/status.json';
        File::ensureDirectoryExists(dirname($path), 0700);
        $status = array_merge($this->status($job) ?? [], $values, ['updated_at' => now()->toIso8601String()]);

        file_put_contents($path.'.tmp', json_encode($status, JSON_UNESCAPED_UNICODE));
        rename($path.'.tmp', $path);
    }

    /** Leftovers of abandoned uploads; a running job touches its status often. */
    protected function pruneOldJobs(): void
    {
        foreach (glob(storage_path('app/panel-transfer/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < now()->subDays(2)->getTimestamp()) {
                File::deleteDirectory($dir);
            }
        }
    }

    /** @param resource|false $in */
    protected function copyStream($in, string $target): void
    {
        $out = fopen($target, 'wb');

        if ($in === false || $out === false || stream_copy_to_stream($in, $out) === false) {
            throw new RuntimeException(__('transfer::transfer.not_a_backup'));
        }

        fclose($in);
        fclose($out);
    }

    protected function gunzip(string $source, string $target): string
    {
        $in = gzopen($source, 'rb');
        $out = fopen($target, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException(__('transfer::transfer.not_a_backup'));
        }

        while (! gzeof($in)) {
            $chunk = gzread($in, 1048576);

            if ($chunk === false) {
                throw new RuntimeException(__('transfer::transfer.not_a_backup'));
            }

            fwrite($out, $chunk);
        }

        gzclose($in);
        fclose($out);
        @unlink($source);

        return $target;
    }
}
