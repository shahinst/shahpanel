<?php

namespace App\Http\Controllers\Api\Marzban;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Marzban\MarzbanInboundTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * اندپوینت‌های متادیتای نمای مرزبان.
 *
 * /api/inbounds و /api/system را میرزا می‌خواند و /api/core/config را ویزویز؛
 * هر سه فقط از دیتابیس محلی و منابع همین ماشین خوانده می‌شوند.
 */
class MetaController extends Controller
{
    /**
     * نسخه‌ای که به ربات اعلام می‌شود.
     *
     * ربات‌ها روی نسخهٔ مرزبان شرط می‌گذارند تا بفهمند کدام قابلیت را دارند؛ یک
     * نسخهٔ جدید اعلام می‌شود تا مسیرهای قدیمی و ناسازگارشان فعال نشود.
     */
    protected const ANNOUNCED_VERSION = '0.8.4';

    public function __construct(protected MarzbanInboundTagService $tags) {}

    /**
     * GET /api/inbounds — «هر پکیج + بازه» یک inbound.
     *
     * قالب مرزبان: نگاشتِ پروتکل به فهرست inbound. میرزا روی همین کلیدها حلقه
     * می‌زند و tag را برای پلن ذخیره می‌کند.
     */
    public function inbounds(Request $request): JsonResponse
    {
        $grouped = [];

        foreach ($this->tags->advertised($request->user()) as $row) {
            $grouped[$row['protocol']][] = [
                'tag' => $row['tag'],
                'protocol' => $row['protocol'],
                // این چند فیلد فقط تزئینی‌اند: کانفیگ واقعی از /sub/{token}
                // می‌آید و پنل مبدأ خودش شبکه و پورت را تعیین می‌کند.
                'network' => 'tcp',
                'tls' => 'tls',
                'port' => 443,
                'sni' => '',
                'host' => [],
                'path' => '',
            ];
        }

        return response()->json($grouped === [] ? new \stdClass() : $grouped);
    }

    /**
     * GET /api/core/config — فهرست تختِ inboundها برای ویزویز.
     */
    public function coreConfig(Request $request): JsonResponse
    {
        $inbounds = [];

        foreach ($this->tags->advertised($request->user()) as $row) {
            $inbounds[] = [
                'tag' => $row['tag'],
                'protocol' => $row['protocol'],
            ];
        }

        return response()->json(['inbounds' => $inbounds]);
    }

    /**
     * GET /api/system — آمار پنل از نگاه همین نماینده.
     *
     * اعداد حافظه و CPU عمداً هرگز صفر نمی‌شوند: میرزا برای نمایش درصد، بر
     * mem_total تقسیم می‌کند و صفر در PHP 8 خطای DivisionByZero می‌دهد و صفحهٔ
     * وضعیت ربات را می‌شکند.
     */
    public function system(Request $request): JsonResponse
    {
        $base = Account::query()->ownedByHierarchy($request->user());

        $total = (clone $base)->count();
        $active = (clone $base)->where('status', AccountStatus::Active->value)->count();
        $used = (int) (clone $base)->sum('data_used_bytes');

        $memory = $this->memory();
        $cores = $this->cpuCores();

        return response()->json([
            'version' => self::ANNOUNCED_VERSION,
            'mem_total' => $memory['total'],
            'mem_used' => $memory['used'],
            'cpu_cores' => $cores,
            'cpu_usage' => $this->cpuUsage($cores),
            'total_user' => $total,
            'users_active' => $active,
            // پنل آپلود و دانلود را جدا نگه نمی‌دارد؛ کل مصرف در سمت دانلود
            // گزارش می‌شود تا جمعِ نمایش‌دادهٔ ربات درست باشد.
            'incoming_bandwidth' => 0,
            'outgoing_bandwidth' => $used,
            'incoming_bandwidth_speed' => 0,
            'outgoing_bandwidth_speed' => 0,
        ]);
    }

    /**
     * حافظهٔ ماشین از /proc، بدون اجرای هیچ فرمانی. روی سیستم‌های بدون /proc
     * مقدار جایگزین برگردانده می‌شود تا هیچ‌گاه صفر نباشد.
     *
     * @return array{total: int, used: int}
     */
    protected function memory(): array
    {
        $fallbackTotal = 1073741824;
        $path = '/proc/meminfo';

        if (! @is_readable($path)) {
            return [
                'total' => $fallbackTotal,
                'used' => max(1, memory_get_usage(true)),
            ];
        }

        $raw = (string) @file_get_contents($path);
        $values = [];

        foreach (['MemTotal', 'MemAvailable'] as $key) {
            if (preg_match('/^'.$key.':\s+(\d+) kB/mi', $raw, $matches) === 1) {
                $values[$key] = ((int) $matches[1]) * 1024;
            }
        }

        $total = $values['MemTotal'] ?? $fallbackTotal;
        $available = $values['MemAvailable'] ?? 0;

        return [
            'total' => max(1, $total),
            'used' => max(0, $total - $available),
        ];
    }

    protected function cpuCores(): int
    {
        $path = '/proc/cpuinfo';

        if (! @is_readable($path)) {
            return 1;
        }

        $raw = (string) @file_get_contents($path);

        return max(1, preg_match_all('/^processor\s*:/mi', $raw));
    }

    /** درصد بار CPU از میانگین بار یک‌دقیقه‌ای، محدود به بازهٔ ۰ تا ۱۰۰. */
    protected function cpuUsage(int $cores): float
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

        if (! is_array($load) || ! isset($load[0])) {
            return 0.0;
        }

        return round(max(0.0, min(100.0, ((float) $load[0] / max(1, $cores)) * 100)), 2);
    }
}
