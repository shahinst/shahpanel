<?php

namespace Tests\Feature;

use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Modules\Transfer\Services\Reencryptor;
use Modules\Transfer\Services\TransferService;
use Modules\Transfer\TransferServiceProvider;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class PanelTransferTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        self::loadTransferModule($this->app);
        // The detached restore would run against the real .env database.
        $this->app->bind(TransferService::class, fn ($app) => new class($app->make(\App\Services\DatabaseBackupService::class)) extends TransferService
        {
            protected function launch(string $job): void {}
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/panel-transfer'));

        parent::tearDown();
    }

    public static function loadTransferModule($app): void
    {
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'Modules\\Transfer\\')) {
                $file = base_path('modules/transfer/src/'.str_replace('\\', '/', substr($class, strlen('Modules\\Transfer\\'))).'.php');

                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
        $app->register(TransferServiceProvider::class);
        $app['router']->getRoutes()->refreshNameLookups();
    }

    public function test_only_the_main_admin_reaches_the_transfer_page(): void
    {
        $main = $this->makeAdmin();
        $other = $this->makeAdmin();

        $this->actingAs($main)->get(route('admin.transfer.index'))->assertOk()->assertSee('transfer-form', false);
        $this->actingAs($other)->get(route('admin.transfer.index'))->assertForbidden();
        $this->actingAs($this->makeAgent())->get(route('admin.transfer.index'))->assertRedirect();
    }

    public function test_chunks_are_appended_in_order_and_the_job_starts_once(): void
    {
        $admin = $this->makeAdmin();
        $job = str_repeat('a', 40);
        $chunk = fn (string $content) => UploadedFile::fake()->createWithContent('chunk', $content);

        $this->actingAs($admin)->post(route('admin.transfer.chunk'), ['job' => $job, 'offset' => 0, 'chunk' => $chunk('abc')])
            ->assertOk()->assertJson(['size' => 3]);
        $this->post(route('admin.transfer.chunk'), ['job' => $job, 'offset' => 7, 'chunk' => $chunk('def')])->assertStatus(409);
        $this->post(route('admin.transfer.chunk'), ['job' => $job, 'offset' => 3, 'chunk' => $chunk('def')])
            ->assertOk()->assertJson(['size' => 6]);
        $this->assertSame('abcdef', file_get_contents(storage_path('app/panel-transfer/'.$job.'/upload.bin')));

        $this->postJson(route('admin.transfer.start'), ['job' => $job])->assertUnprocessable();
        $status = $this->postJson(route('admin.transfer.start'), ['job' => $job, 'confirm' => '1'])->assertOk()->json('status_url');
        $this->postJson(route('admin.transfer.start'), ['job' => $job, 'confirm' => '1'])->assertStatus(409);
        $this->post(route('admin.transfer.chunk'), ['job' => $job, 'offset' => 0, 'chunk' => $chunk('x')])->assertStatus(409);

        // The status needs no session: the panel's sessions are replaced mid-way.
        auth()->logout();
        $this->getJson($status)->assertOk()->assertJson(['state' => 'queued']);
        $this->getJson(route('transfer.status', str_repeat('b', 40)))->assertNotFound();
    }

    public function test_secrets_inside_json_are_moved_to_the_new_key(): void
    {
        $from = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $to = new Encrypter(random_bytes(32), 'AES-256-CBC');

        $payload = $from->encrypt('bot-token', false);
        $json = json_encode(['token_enc' => $payload, 'name' => 'shop']);
        $converted = (new Reencryptor($from, $to, 'new'))->convert($json);
        $decoded = json_decode($converted, true);

        $this->assertSame('shop', $decoded['name']);
        $this->assertSame('bot-token', $to->decrypt($decoded['token_enc'], false));
        $this->assertSame('bot-token', $to->decrypt((new Reencryptor($from, $to, 'new'))->convert($payload), false));
        // A value some other key wrote is left as it was.
        $foreign = (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encrypt('x', false);
        $this->assertSame($foreign, (new Reencryptor($from, $to, 'new'))->convert($foreign));
    }
}
