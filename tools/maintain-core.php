<?php
/**
 * سامانه امور مشتریان — هسته maintain (بدون Laravel)
 */

if (! isset($basePath) || $basePath === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'maintain: basePath missing';
    exit;
}

// This page runs migrations, clears caches and prints database status, .env
// values and log tails — all without Laravel, so none of the panel's own
// authentication applies to it. It therefore stays shut until an operator puts
// a token in .env deliberately, and answers 404 (not 403) to everyone else so a
// scanner cannot even confirm the file is here.
//
//   echo "MAINTAIN_TOKEN=$(php -r 'echo bin2hex(random_bytes(16));')" >> .env
//   https://panel.example.com/maintain.php?key=<token>
//
// Clear MAINTAIN_TOKEN again once the incident is over.
$maintainToken = maintainEnvValue($basePath, 'MAINTAIN_TOKEN') ?? '';
$maintainSupplied = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';

if ($maintainToken === '' || ! hash_equals($maintainToken, $maintainSupplied)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

// Only enabled past the token check: the traces below carry absolute paths and
// database errors.
ini_set('display_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null || ! in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    if (headers_sent()) {
        echo "\n\nFATAL: ".$err['message'].' in '.$err['file'].':'.$err['line'];
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
    echo maintainPage('خطای PHP', '<p class="err"><strong>Fatal:</strong> '
        .htmlspecialchars($err['message'], ENT_QUOTES, 'UTF-8').'</p>'
        .'<pre>'.htmlspecialchars($err['file'].':'.$err['line'], ENT_QUOTES, 'UTF-8').'</pre>');
});

try {
    maintainRun($basePath);
} catch (Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
    echo maintainPage('خطا', '<p class="err">'.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</p>'
        .'<pre>'.htmlspecialchars($e->getFile().':'.$e->getLine(), ENT_QUOTES, 'UTF-8').'</pre>');
}

function maintainRun(string $basePath): void
{
    $lockFile = $basePath.'/.installed.lock';
    $vendorAutoload = $basePath.'/vendor/autoload.php';

    $action = '';
    if (isset($_GET['do']) && is_string($_GET['do'])) {
        $action = $_GET['do'];
    } elseif (isset($_POST['action']) && is_string($_POST['action'])) {
        $action = $_POST['action'];
    }

    $output = '';
    $error = '';
    $ran = false;
    $checks = maintainMissingFiles($basePath);
    $diagnostics = maintainDiagnostics($basePath);

    if ($action === 'off-maintenance') {
        $output .= maintainClearMaintenanceMode($basePath);
        $ran = true;
        $diagnostics = maintainDiagnostics($basePath);
    }

    if ($action !== '' && $action !== 'off-maintenance') {
        if ($action === 'cache' || $action === 'all') {
            $output .= implode("\n", maintainClearCache($basePath));
            $ran = true;

            if (is_file($vendorAutoload)) {
                $artisan = maintainArtisan($basePath, $vendorAutoload, ['route:clear', 'config:clear', 'view:clear', 'cache:clear']);
                if ($artisan['error'] !== '') {
                    $output .= "\n\nArtisan: ".$artisan['error'];
                } elseif ($artisan['output'] !== '') {
                    $output .= "\n\nArtisan:\n".$artisan['output'];
                }
            }
        }

        if ($action === 'queue') {
            if (! is_file($vendorAutoload)) {
                $error = 'vendor/autoload.php یافت نشد.';
            } else {
                $queue = maintainArtisan($basePath, $vendorAutoload, ['tunneling:run-queue']);
                if ($queue['error'] !== '') {
                    $output .= ($output !== '' ? "\n\n" : '')."queue:\n".$queue['error'];
                } else {
                    $output .= ($output !== '' ? "\n\n" : '')."queue:\n".$queue['output'];
                    $ran = true;
                }
            }
        }

        if ($action === 'tunneling' || $action === 'all') {
            if (! is_file($vendorAutoload)) {
                $error = 'vendor/autoload.php یافت نشد.';
            } else {
                $tunneling = maintainArtisan($basePath, $vendorAutoload, ['optimize:clear', 'tunneling:doctor', 'tunneling:run-queue', 'view:cache']);
                if ($tunneling['error'] !== '') {
                    $output .= ($output !== '' ? "\n\n" : '')."tunneling:\n".$tunneling['error'];
                } else {
                    $output .= ($output !== '' ? "\n\n" : '')."tunneling:\n".$tunneling['output'];
                    $ran = true;
                }
            }
        }

        if ($action === 'migrate' || $action === 'all') {
            if (! is_file($vendorAutoload)) {
                $error = 'vendor/autoload.php یافت نشد.';
            } else {
                $migrate = maintainArtisan($basePath, $vendorAutoload, ['migrate --force']);
                if ($migrate['error'] !== '') {
                    $error = $migrate['error'];
                } else {
                    $output .= ($output !== '' ? "\n\n" : '')."migrate:\n".$migrate['output'];
                    $ran = true;
                }
            }
        }
    }

    $body = '<p class="hint">PHP '.PHP_VERSION.'<br>مسیر: <code>'
        .htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8').'</code><br>'
        .'این فایل: <code>'.htmlspecialchars(__FILE__, ENT_QUOTES, 'UTF-8').'</code></p>';

    $body .= $diagnostics['html'];

    if (! is_file($lockFile)) {
        $body .= '<p class="err">فایل <code>.installed.lock</code> نیست — اول <code>php artisan install:finalize</code> را اجرا کنید.</p>';
    }

    if ($error !== '') {
        $body .= '<p class="err"><strong>خطا:</strong> '.htmlspecialchars($error, ENT_QUOTES, 'UTF-8').'</p>';
    }

    if ($checks !== '') {
        $body .= '<div class="box warn"><strong>فایل‌های گم‌شده:</strong><pre>'
            .htmlspecialchars($checks, ENT_QUOTES, 'UTF-8').'</pre></div>';
    }

    if ($ran && $error === '') {
        $body .= '<p class="ok">انجام شد.</p>';
        if ($output !== '') {
            $body .= '<pre>'.htmlspecialchars(trim($output), ENT_QUOTES, 'UTF-8').'</pre>';
        }
        $body .= '<p><a class="btn" href="/">بازگشت</a></p>';
    } else {
        $body .= '<p><strong>بعد از ریستارت سرور</strong> معمولاً MySQL یا PHP-FPM خاموش است — در SSH:</p>
        <pre style="font-size:.78rem;direction:ltr;text-align:left">systemctl start mysql
# یا: systemctl start mariadb
systemctl start php8.2-fpm
# یا: systemctl start php-fpm
systemctl start nginx
# یا: systemctl start apache2</pre>';
        $body .= '<p>برای رفع خطای پنل:</p>
            <p><a class="btn" href="?do=cache">۱) پاک‌سازی cache</a>
            <a class="btn btn-secondary" href="?do=off-maintenance" style="margin-right:8px">۲) خاموش کردن maintenance</a></p>
            <form method="post" style="margin-top:12px">
                <input type="hidden" name="action" value="cache">
                <button type="submit" class="btn btn-secondary">پاک‌سازی cache (POST)</button>
            </form>
            <form method="post" style="margin-top:8px">
                <input type="hidden" name="action" value="migrate">
                <button type="submit" class="btn btn-secondary">migrate</button>
            </form>
            <form method="post" style="margin-top:8px">
                <input type="hidden" name="action" value="all">
                <button type="submit" class="btn btn-secondary">cache + migrate + tunneling doctor</button>
            </form>
            <form method="post" style="margin-top:8px">
                <input type="hidden" name="action" value="queue">
                <button type="submit" class="btn btn-secondary">پردازش صف تانلینگ (tunneling:run-queue)</button>
            </form>';
    }

    header('Content-Type: text/html; charset=utf-8');
    echo maintainPage('نگهداری سامانه امور مشتریان', $body);
}

/**
 * @return array{html: string, ok: bool}
 */
function maintainDiagnostics(string $basePath): array
{
    $rows = [];
    $ok = true;

    $maintenanceFile = $basePath.'/storage/framework/maintenance.php';
    $downFile = $basePath.'/storage/framework/down';

    if (is_file($maintenanceFile)) {
        $rows[] = ['err', 'حالت maintenance Laravel فعال است — پنل بالا نمی‌آید.', $maintenanceFile];
        $ok = false;
    }

    if (is_file($downFile)) {
        $rows[] = ['err', 'فایل down (artisan down) وجود دارد.', $downFile];
        $ok = false;
    }

    if (! is_file($basePath.'/.env')) {
        $rows[] = ['err', 'فایل .env وجود ندارد.', ''];
        $ok = false;
    } else {
        $rows[] = ['ok', 'فایل .env موجود است.', ''];
    }

    if (! is_file($basePath.'/vendor/autoload.php')) {
        $rows[] = ['err', 'پوشه vendor نیست — روی سرور composer install اجرا کنید.', ''];
        $ok = false;
    } else {
        $rows[] = ['ok', 'vendor/autoload.php موجود است.', ''];
    }

    $storage = $basePath.'/storage';
    $logs = $basePath.'/storage/logs';
    if (! is_writable($storage)) {
        $rows[] = ['err', 'پوشه storage قابل نوشتن نیست (chmod 775).', $storage];
        $ok = false;
    } else {
        $rows[] = ['ok', 'storage قابل نوشتن است.', ''];
    }

    if (is_dir($logs)) {
        $logFile = $logs.'/laravel.log';
        $tail = maintainLogTail($logFile, 12);
        if ($tail !== '') {
            $rows[] = ['warn', 'آخرین خطاهای laravel.log:', $tail];
        }
    }

    $db = maintainDatabasePing($basePath);
    $rows[] = [$db['ok'] ? 'ok' : 'err', 'اتصال دیتابیس: '.$db['message'], ''];

    if (! $db['ok']) {
        $ok = false;
    }

    $boot = maintainLaravelBootTest($basePath);
    $rows[] = [$boot['ok'] ? 'ok' : 'err', 'بوت Laravel: '.$boot['message'], $boot['detail'] ?? ''];

    if (! $boot['ok']) {
        $ok = false;
    }

    $tunneling = maintainTunnelingDiagnostics($basePath);
    foreach ($tunneling['rows'] as $row) {
        $rows[] = $row;
        if ($row[0] === 'err') {
            $ok = false;
        }
    }

    $queuePending = maintainQueuePendingCount($basePath);
    if ($queuePending > 0) {
        $rows[] = ['warn', "صف Laravel: {$queuePending} job در انتظار — worker اجرا نشده؟", 'php artisan tunneling:run-queue'];
    }

    $html = '<div class="box '.($ok ? 'okbox' : 'warn').'"><strong>تشخیص خودکار</strong><ul style="margin:8px 0 0;padding-right:1.2rem">';

    foreach ($rows as [$level, $text, $detail]) {
        $class = match ($level) {
            'ok' => 'ok',
            'warn' => 'hint',
            default => 'err',
        };
        $html .= '<li class="'.$class.'">'.htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        if ($detail !== '') {
            $html .= '<pre style="margin:6px 0 0;font-size:.72rem;max-height:140px;overflow:auto">'
                .htmlspecialchars($detail, ENT_QUOTES, 'UTF-8').'</pre>';
        }

        $html .= '</li>';
    }

    $html .= '</ul></div>';

    return ['html' => $html, 'ok' => $ok];
}

function maintainClearMaintenanceMode(string $basePath): string
{
    $lines = ['=== خاموش کردن maintenance ==='];
    $paths = [
        $basePath.'/storage/framework/maintenance.php',
        $basePath.'/storage/framework/down',
    ];

    foreach ($paths as $path) {
        if (is_file($path) && @unlink($path)) {
            $lines[] = 'حذف '.str_replace($basePath.'/', '', $path);
        }
    }

    return implode("\n", $lines);
}

function maintainLogTail(string $path, int $maxLines): string
{
    if (! is_file($path) || ! is_readable($path)) {
        return '';
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES);

    if (! is_array($lines) || $lines === []) {
        return '';
    }

    return implode("\n", array_slice($lines, -$maxLines));
}

/**
 * @return array{ok: bool, message: string}
 */
function maintainDatabasePing(string $basePath): array
{
    if (! is_file($basePath.'/.env')) {
        return ['ok' => false, 'message' => 'بدون .env'];
    }

    $host = maintainEnvValue($basePath, 'DB_HOST') ?? '127.0.0.1';
    $port = maintainEnvValue($basePath, 'DB_PORT') ?? '3306';
    $database = maintainEnvValue($basePath, 'DB_DATABASE') ?? '';
    $username = maintainEnvValue($basePath, 'DB_USERNAME') ?? 'root';
    $password = maintainEnvValue($basePath, 'DB_PASSWORD') ?? '';

    if ($database === '') {
        return ['ok' => false, 'message' => 'DB_DATABASE خالی است'];
    }

    if (! extension_loaded('pdo_mysql')) {
        return ['ok' => false, 'message' => 'افزونه pdo_mysql فعال نیست'];
    }

    try {
        $dsn = 'mysql:host='.$host.';port='.$port.';dbname='.$database.';charset=utf8mb4';
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->query('SELECT 1');

        return ['ok' => true, 'message' => 'OK ('.$host.':'.$port.'/'.$database.')'];
    } catch (Throwable $e) {
        $hint = str_contains(strtolower($e->getMessage()), 'connection refused')
            ? ' — احتمالاً MySQL بعد از ریستارت روشن نشده (systemctl start mysql)'
            : '';

        return ['ok' => false, 'message' => $e->getMessage().$hint];
    }
}

function maintainEnvValue(string $basePath, string $key): ?string
{
    $path = $basePath.'/.env';

    if (! is_readable($path)) {
        return null;
    }

    $content = @file_get_contents($path);

    if ($content === false) {
        return null;
    }

    if (preg_match('/^'.preg_quote($key, '/').'\s*=\s*(.*)$/m', $content, $matches) !== 1) {
        return null;
    }

    $value = trim($matches[1]);

    if (
        (str_starts_with($value, '"') && str_ends_with($value, '"'))
        || (str_starts_with($value, "'") && str_ends_with($value, "'"))
    ) {
        $value = substr($value, 1, -1);
    }

    return $value !== '' ? $value : null;
}

/**
 * @return array{ok: bool, message: string, detail?: string}
 */
function maintainLaravelBootTest(string $basePath): array
{
    $vendor = $basePath.'/vendor/autoload.php';

    if (! is_file($vendor)) {
        return ['ok' => false, 'message' => 'vendor موجود نیست'];
    }

    try {
        require $vendor;
        $app = require $basePath.'/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        return ['ok' => true, 'message' => 'بدون خطا'];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => $e->getMessage(),
            'detail' => $e->getFile().':'.$e->getLine(),
        ];
    }
}

/**
 * @return array{rows: list<array{0: string, 1: string, 2: string}>}
 */
function maintainTunnelingDiagnostics(string $basePath): array
{
    $rows = [];
    $vendor = $basePath.'/vendor/autoload.php';

    if (! is_file($basePath.'/app/Support/TunnelingSchema.php')) {
        $rows[] = ['err', 'فایل TunnelingSchema.php آپلود نشده — /admin/tunneling خطای 500 می‌دهد.', ''];

        return ['rows' => $rows];
    }

    if (! is_file($vendor)) {
        $rows[] = ['warn', 'تشخیص جداول تانلینگ: vendor نیست.', ''];

        return ['rows' => $rows];
    }

    try {
        require $vendor;
        $app = require $basePath.'/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        $missing = \App\Support\TunnelingSchema::missingTables();

        if ($missing === []) {
            $rows[] = ['ok', 'تانلینگ: همه جداول DB موجود است.', ''];
        } else {
            $rows[] = ['err', 'تانلینگ: جداول migrate نشده — php artisan migrate --force', implode(', ', $missing)];
        }
    } catch (Throwable $e) {
        $rows[] = ['err', 'تشخیص تانلینگ: '.$e->getMessage(), $e->getFile().':'.$e->getLine()];
    }

    return ['rows' => $rows];
}

function maintainQueuePendingCount(string $basePath): int
{
    $vendor = $basePath.'/vendor/autoload.php';

    if (! is_file($vendor)) {
        return 0;
    }

    try {
        require $vendor;
        $app = require $basePath.'/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        if (! \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) \Illuminate\Support\Facades\DB::table('jobs')
            ->whereIn('queue', ['tunneling', 'default', 'tunneling-low'])
            ->count();
    } catch (Throwable) {
        return 0;
    }
}

function maintainMissingFiles(string $basePath): string
{
    $required = [
        'bootstrap/app.php',
        'routes/web.php',
        'vendor/autoload.php',
        'app/Services/EndUserService.php',
        'resources/views/client/shop/index.blade.php',
        'app/Support/TunnelingSchema.php',
        'config/tunneling.php',
        'lang/fa/tunneling.php',
        'app/Http/Controllers/Admin/TunnelGroupController.php',
        'resources/views/admin/tunneling/index.blade.php',
        'resources/views/admin/tunneling/setup-required.blade.php',
        'app/Services/Tunneling/TunnelGroupOrchestrator.php',
    ];

    $missing = [];
    foreach ($required as $relative) {
        if (! is_file($basePath.'/'.$relative)) {
            $missing[] = $relative;
        }
    }

    return implode("\n", $missing);
}

function maintainClearCache(string $basePath): array
{
    $lines = ['=== cache دستی ==='];

    foreach ([
        'bootstrap/cache/config.php',
        'bootstrap/cache/routes-v7.php',
        'bootstrap/cache/routes.php',
        'bootstrap/cache/services.php',
        'bootstrap/cache/packages.php',
        'bootstrap/cache/events.php',
    ] as $rel) {
        $path = $basePath.'/'.$rel;
        if (is_file($path) && @unlink($path)) {
            $lines[] = 'حذف '.$rel;
        }
    }

    $lines[] = maintainWipeFiles($basePath.'/storage/framework/views');
    $lines[] = maintainWipeFiles($basePath.'/storage/framework/cache/data', true);

    return $lines;
}

function maintainWipeFiles(string $dir, bool $recursive = false): string
{
    if (! is_dir($dir)) {
        return basename($dir).': پوشه نیست';
    }

    $count = maintainDeleteInDir($dir, $recursive);

    return basename($dir).': '.$count.' فایل';
}

function maintainDeleteInDir(string $dir, bool $recursive): int
{
    $count = 0;
    $items = @scandir($dir) ?: [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir.'/'.$item;

        if (is_dir($path)) {
            if ($recursive) {
                $count += maintainDeleteInDir($path, true);
                @rmdir($path);
            }
            continue;
        }

        if ($item === '.gitignore') {
            continue;
        }

        if (@unlink($path)) {
            $count++;
        }
    }

    return $count;
}

function maintainArtisan(string $basePath, string $vendorAutoload, array $commands): array
{
    try {
        require $vendorAutoload;
        $app = require $basePath.'/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        Illuminate\Support\Facades\Config::set('database.connections.mysql.charset', 'utf8mb4');
        Illuminate\Support\Facades\Config::set('database.connections.mysql.collation', 'utf8mb4_unicode_ci');
        Illuminate\Support\Facades\DB::purge('mysql');

        $lines = [];
        foreach ($commands as $command) {
            if ($command === 'migrate --force') {
                Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
            } else {
                Illuminate\Support\Facades\Artisan::call($command);
            }
            $out = trim((string) Illuminate\Support\Facades\Artisan::output());
            $lines[] = $command.($out !== '' ? ":\n".$out : ' OK');
        }

        return ['output' => implode("\n", $lines), 'error' => ''];
    } catch (Throwable $e) {
        return ['output' => '', 'error' => $e->getMessage()];
    }
}

function maintainPage(string $title, string $body): string
{
    return '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        .htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        .'</title><style>body{font-family:Tahoma,sans-serif;background:#f1f5f9;margin:0;padding:24px;line-height:1.7;color:#0f172a}.wrap{max-width:640px;margin:0 auto;background:#fff;border-radius:16px;padding:28px;box-shadow:0 4px 24px rgba(0,0,0,.08)}h1{margin:0 0 12px;font-size:1.3rem}.btn{display:inline-block;background:#2563eb;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;border:none;cursor:pointer;font-size:1rem}.btn-secondary{background:#64748b}.ok{color:#059669;font-weight:700}.err{color:#dc2626}.hint{font-size:.85rem;color:#64748b}code{background:#f1f5f9;padding:2px 6px;border-radius:4px}pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;font-size:.8rem;white-space:pre-wrap}.box.warn{background:#fff7ed;border:1px solid #fdba74;border-radius:8px;padding:12px;margin:12px 0}.box.okbox{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:12px;margin:12px 0}ul li{margin-bottom:8px}</style></head><body><div class="wrap"><h1>'
        .htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        .'</h1>'.$body.'</div></body></html>';
}
