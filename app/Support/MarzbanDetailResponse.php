<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * شکل خطای واحد نمای مرزبان.
 *
 * مرزبان (که در واقع FastAPI است) خطا را با کلید detail می‌دهد و ربات‌ها هم
 * همین را می‌خوانند. هندلرهای withExceptions پروژه فقط روی api/v1/* فعال‌اند،
 * پس این مسیر باید خودش شکل خطا را نگه دارد.
 */
final class MarzbanDetailResponse
{
    public static function make(string $message, int $status = 400): JsonResponse
    {
        return response()->json(['detail' => $message], $status);
    }
}
