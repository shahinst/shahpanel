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
 * هر سه فقط از دیتابیس محلی خوانده می‌شوند و هیچ مشخصهٔ واقعی‌ای از ماشین میزبان
 * بیرون نمی‌دهند.
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

    /**
     * منابع ماشین: مقادیر ثابت و ساختگی، نه خواندن واقعی.
     *
     * چرا: اعداد واقعی مشخصات میزبان پنل را به هر نمایندهٔ احراز هویت‌شده لو
     * می‌داد — اندازهٔ رم، تعداد هسته و بار لحظه‌ای — یعنی هم نقشهٔ ظرفیت برای
     * انتخاب زمان حمله و هم داده‌ای که فروشنده هیچ کاری با آن ندارد. ربات‌ها
     * فقط به «صفر نبودن و معقول بودن» نیاز دارند، پس عدد ثابت کافی است.
     */
    protected const PLACEHOLDER_MEM_TOTAL = 8589934592;

    protected const PLACEHOLDER_MEM_USED = 3221225472;

    protected const PLACEHOLDER_CPU_CORES = 4;

    protected const PLACEHOLDER_CPU_USAGE = 12.5;

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
     * شمارش کاربران واقعی و محدود به سلسله‌مراتب همین نماینده است؛ اعداد حافظه و
     * CPU ثابت و ساختگی‌اند (بالا را ببینید) و عمداً هرگز صفر نمی‌شوند: میرزا برای
     * نمایش درصد بر mem_total و cpu_cores تقسیم می‌کند و صفر در PHP 8 خطای
     * DivisionByZero می‌دهد و صفحهٔ وضعیت ربات را می‌شکند.
     */
    public function system(Request $request): JsonResponse
    {
        $base = Account::query()->ownedByHierarchy($request->user());

        $total = (clone $base)->count();
        $active = (clone $base)->where('status', AccountStatus::Active->value)->count();
        $used = (int) (clone $base)->sum('data_used_bytes');

        return response()->json([
            'version' => self::ANNOUNCED_VERSION,
            'mem_total' => self::PLACEHOLDER_MEM_TOTAL,
            'mem_used' => self::PLACEHOLDER_MEM_USED,
            'cpu_cores' => self::PLACEHOLDER_CPU_CORES,
            'cpu_usage' => self::PLACEHOLDER_CPU_USAGE,
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
}
