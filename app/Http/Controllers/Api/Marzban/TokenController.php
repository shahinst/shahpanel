<?php

namespace App\Http\Controllers\Api\Marzban;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiTokenService;
use App\Services\Marzban\MarzbanTokenService;
use App\Services\TwoFactorService;
use App\Support\MarzbanDetailResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /api/admin/token — معادل اندپوینت ورود مرزبان.
 *
 * بدنه form-encoded است (username/password) و پاسخ موفق دقیقاً
 * {"access_token": "...", "token_type": "bearer"}.
 *
 * ویزویز توکن را کش نمی‌کند و پیش از هر عملیات با تایم‌اوت ۳ ثانیه‌ای این مسیر
 * را صدا می‌زند، پس هیچ کار سنگینی این‌جا انجام نمی‌شود: یک کوئری کاربر، و در
 * حالت کش‌خورده فقط یک کوئری روی api_tokens (بدون bcrypt و بدون درج ردیف).
 */
class TokenController extends Controller
{
    /**
     * سقف تلاش ناموفق. فقط شکست شمرده می‌شود، پس ورودِ موفقِ پرتکرار ربات هرگز
     * قفل نمی‌شود — همان رفتاری که ویزویز به آن نیاز دارد.
     */
    protected const MAX_FAILURES = 10;

    protected const FAILURE_DECAY_SECONDS = 900;

    public function __construct(
        protected MarzbanTokenService $marzbanTokens,
        protected TwoFactorService $twoFactor,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '' || mb_strlen($username) > 255) {
            return MarzbanDetailResponse::make(__('marzban.credentials_required'), 422);
        }

        $key = 'marzban-token:'.sha1(mb_strtolower($username).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            return MarzbanDetailResponse::make(
                __('marzban.login_throttled', ['seconds' => RateLimiter::availableIn($key)]),
                429,
            );
        }

        $user = User::query()
            ->whereNull('deleted_at')
            ->where('username', $username)
            ->first();

        if ($user === null) {
            RateLimiter::hit($key, self::FAILURE_DECAY_SECONDS);

            return MarzbanDetailResponse::make(__('marzban.login_failed'), 401);
        }

        // همان قاعدهٔ /api/v1/auth/login: ادمین از راه API نمی‌فروشد.
        if (! in_array($user->role, ApiTokenService::ALLOWED_ROLES, true)) {
            RateLimiter::hit($key, self::FAILURE_DECAY_SECONDS);

            return MarzbanDetailResponse::make(__('marzban.login_role_not_allowed'), 403);
        }

        if ($user->status !== UserStatus::Active) {
            RateLimiter::hit($key, self::FAILURE_DECAY_SECONDS);

            return MarzbanDetailResponse::make(__('marzban.login_suspended'), 403);
        }

        // پروتکل مرزبان جایی برای کد دومرحله‌ای ندارد و رد کردن این بررسی یعنی
        // دور زدن یک لایهٔ امنیتی که کاربر خودش روشن کرده؛ پس جواب روشن می‌دهیم
        // تا حساب دیگری برای ربات ساخته شود.
        if ($this->twoFactor->isEnabled($user)) {
            return MarzbanDetailResponse::make(__('marzban.two_factor_not_supported'), 403);
        }

        $token = $this->marzbanTokens->authenticate($user, $password);

        if ($token === null) {
            RateLimiter::hit($key, self::FAILURE_DECAY_SECONDS);

            Log::warning('Marzban facade login failed', [
                'username' => $username,
                'ip' => $request->ip(),
            ]);

            return MarzbanDetailResponse::make(__('marzban.login_failed'), 401);
        }

        RateLimiter::clear($key);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
        ]);
    }
}
