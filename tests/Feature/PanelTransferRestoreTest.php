<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Transfer\Services\TransferService;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;
use ZipArchive;

/**
 * The restore drops and recreates every table, which no test transaction
 * survives, so this class migrates the test database itself and leaves it
 * fresh for the tests after it.
 */
class PanelTransferRestoreTest extends TestCase
{
    use CreatesPanelData;

    private ?string $modulesCache = null;

    /** @var list<string> */
    private array $backupsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        PanelTransferTest::loadTransferModule($this->app);
        config(['database.backup.retention' => 1000]);

        $cache = storage_path('app/modules_active.json');
        $this->modulesCache = is_file($cache) ? (string) file_get_contents($cache) : null;
        $this->backupsBefore = glob(storage_path('app/backups/database/*')) ?: [];

        Artisan::call('migrate:fresh');
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh');
        Artisan::call('up');

        $cache = storage_path('app/modules_active.json');
        $this->modulesCache === null ? @unlink($cache) : file_put_contents($cache, $this->modulesCache);
        array_map('unlink', array_diff(glob(storage_path('app/backups/database/*')) ?: [], $this->backupsBefore));
        File::deleteDirectory(storage_path('app/panel-transfer'));

        parent::tearDown();
    }

    /** What the Telegram bot sends: a zip around the gzipped dump. */
    private function telegramBackup(): string
    {
        $dump = app(DatabaseBackupService::class)->createBackup()['path'];
        $zip = new ZipArchive;
        $zip->open($path = $dump.'.zip', ZipArchive::CREATE);
        $zip->addFile($dump, basename($dump));
        $zip->close();

        return $path;
    }

    /** The new server: same code, its own APP_KEY. */
    private function switchKey(): string
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        config(['app.key' => $key]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        return $key;
    }

    private function runJob(string $backup, string $appKey): array
    {
        $service = app(TransferService::class);
        $job = str_repeat('j', 40);
        File::ensureDirectoryExists($service->dir($job));
        copy($backup, $service->dir($job).'/upload.bin');
        file_put_contents($service->dir($job).'/job.json', json_encode(['key' => $appKey]));

        $service->run($job);

        return $service->status($job);
    }

    public function test_a_backup_from_the_old_server_replaces_the_fresh_panel_with_its_secrets(): void
    {
        $owner = $this->makeAdmin();
        $server = $this->makeServer('sanaei', ['password_enc' => 'server-pass']);
        $oldKey = (string) config('app.key');
        $backup = $this->telegramBackup();

        $this->switchKey();
        $fresh = $this->makeAdmin();
        Server::query()->whereKey($server->id)->delete();

        $status = $this->runJob($backup, $oldKey);

        $this->assertSame('done', $status['state'], (string) ($status['message'] ?? ''));
        $this->assertTrue(User::query()->whereKey($owner->id)->where('email', $owner->email)->exists());
        $this->assertFalse(User::query()->where('email', $fresh->email)->exists());
        $this->assertSame('server-pass', Server::query()->findOrFail($server->id)->password_enc);
        $this->assertSame('active', DB::table('modules')->where('slug', 'transfer')->value('status'));
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertFileDoesNotExist(app(TransferService::class)->dir(str_repeat('j', 40)).'/job.json');
    }

    public function test_a_wrong_key_is_refused_before_anything_changes(): void
    {
        $this->makeAdmin();
        $this->makeServer('sanaei', ['password_enc' => 'server-pass']);
        $backup = $this->telegramBackup();

        $this->switchKey();
        $fresh = $this->makeAdmin();

        $status = $this->runJob($backup, 'base64:'.base64_encode(random_bytes(32)));

        $this->assertSame('failed', $status['state']);
        $this->assertSame('untouched', $status['outcome']);
        $this->assertSame(__('transfer::transfer.key_mismatch'), $status['message']);
        $this->assertTrue(User::query()->where('email', $fresh->email)->exists());
        $this->assertFalse(app()->isDownForMaintenance());
    }
}
