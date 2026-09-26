<?php

namespace App\Services\ServerBackup;

use App\Models\Server;
use App\Services\SanaeiService;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Backs a Sanaei / 3x-ui server up by downloading the panel's own SQLite
 * database. Everything worth keeping - inbounds, clients, hosts, settings,
 * traffic counters - lives in that single file, so there is nothing to split
 * into JSON sections. The .db is written into the backup directory next to
 * metadata.json/manifest.json and ServerBackupArchiver zips it exactly the way
 * it zips the MikroTik .backup file.
 */
class SanaeiServerBackupCollector implements ServerBackupCollector
{
    public function __construct(
        protected SanaeiService $sanaei,
    ) {}

    public function supports(Server $server): bool
    {
        return $server->isSanaei();
    }

    public function collect(Server $server, array $paths): array
    {
        $bytes = $this->sanaei->client($server)->downloadDatabase();

        // null یعنی هیچ‌کدام از مسیرهای getDb روی این ساخت وجود ندارد (پنلِ خیلی
        // قدیمی). دلیل همین‌جا صریح می‌شود تا در گزارش تلگرام خوانده شود، وگرنه
        // ادمین فقط «ناموفق» می‌بیند بدون این‌که بداند چه چیزی را باید درست کند.
        if ($bytes === null) {
            throw new RuntimeException(__('server_backups.sanaei_getdb_unavailable', [
                'routes' => '/panel/api/server/getDb, /server/getDb',
            ]));
        }

        $filename = 'sanaei-'.$paths['folder'].'.db';
        $localPath = $paths['absolute'].DIRECTORY_SEPARATOR.$filename;

        $written = File::put($localPath, $bytes);

        // امضای SQLite را خودِ کلاینت پیش از برگرداندن بایت‌ها بررسی کرده؛ این‌جا
        // فقط نوشتن روی دیسک سنجیده می‌شود، چون دیسکِ پر فایل را نصفه می‌نویسد و
        // یک .db بریده هم مثل فایل صفربایتی بی‌ارزش است.
        if ($written === false || $written !== strlen($bytes)) {
            if (is_file($localPath)) {
                @unlink($localPath);
            }

            throw new RuntimeException(__('server_backups.sanaei_database_write_failed', [
                'path' => $localPath,
            ]));
        }

        return [
            'format' => 'sanaei_database',
            'sections' => [],
            'files' => [[
                'name' => 'panel_database',
                'label' => $filename,
                'file' => $filename,
                'bytes' => $written,
                'type' => 'sanaei_database',
            ]],
            'errors' => [],
        ];
    }
}
