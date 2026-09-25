<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\LoginCaptchaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginCaptchaController extends Controller
{
    public function refresh(Request $request, LoginCaptchaService $captcha): JsonResponse
    {
        $payload = $captcha->issue($request);

        // فقط تصویر و توکن به کلاینت می‌رود؛ خودِ پاسخ کپچا سمت سرور (HMAC در
        // سشن) می‌ماند تا این نقطه به یک سرویس «کد را به من بگو» تبدیل نشود.
        return response()->json([
            'token' => $payload['token'],
            'svg' => $payload['svg'],
        ]);
    }
}
