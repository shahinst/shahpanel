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

        return response()->json([
            'token' => $payload['token'],
            'display' => $payload['display'],
            'svg' => $payload['svg'],
        ]);
    }
}
