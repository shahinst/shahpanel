<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogActivity
{
    /**
     * @var array<int, string>
     */
    protected array $except = [
        'password',
        'password_confirmation',
        'current_password',
        '_token',
        '_method',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user === null || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        // در حالت جانشینی، user() همان کاربرِ جانشین‌شده است. بدون ثبت شناسهٔ
        // جانشین، کارِ یک نماینده داخل نشست فروشنده تنها به نام فروشنده ثبت
        // می‌شد و ردّ حساب واقعی از بین می‌رفت.
        $impersonatorId = $request->session()->get(ImpersonationService::SESSION_IMPERSONATOR_ID);

        try {
            ActivityLog::query()->create([
                'user_id' => $user->id,
                'action' => $request->method().' '.$request->path(),
                'entity_type' => null,
                'entity_id' => null,
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'payload' => [
                    'route' => $request->route()?->getName(),
                    'input' => $request->except($this->except),
                    'impersonator_id' => $impersonatorId === null ? null : (int) $impersonatorId,
                ],
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Request activity log failed', [
                'route' => $request->route()?->getName(),
                'path' => $request->path(),
                'error' => $exception->getMessage(),
            ]);
        }

        return $response;
    }
}
