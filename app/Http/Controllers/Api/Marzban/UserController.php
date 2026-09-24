<?php

namespace App\Http\Controllers\Api\Marzban;

use App\Enums\AccountBillingContext;
use App\Enums\AccountStatus;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\User;
use App\Services\AccountBillingPackageService;
use App\Services\AccountService;
use App\Services\Marzban\MarzbanInboundTagService;
use App\Services\Marzban\MarzbanUserPresenter;
use App\Services\PackageCategoryService;
use App\Services\ServerSelectionService;
use App\Services\SubscriptionFeedService;
use App\Support\AccountNameValidator;
use App\Support\MarzbanDetailResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * کاربرانِ نمای مرزبان.
 *
 * هر عملیات روی سرویس‌های موجود پنل سوار است (AccountService) تا فروشِ ربات
 * دقیقاً مثل فروش داخل پنل کیف‌پول را کم کند، فاکتور بسازد و در گزارش‌ها بیاید.
 * هیچ‌جا مسیر حساب‌داری دور زده نمی‌شود.
 *
 * دامنهٔ دید همه‌جا Account::ownedByHierarchy است، پس یک نماینده تنها اکانت‌های
 * زیرمجموعهٔ خودش را می‌بیند و تغییر می‌دهد.
 */
class UserController extends Controller
{
    /** پیش‌فرض و سقف صفحه‌بندی /api/users؛ مرزبان سقف ندارد ولی خروجی ما links دارد و سنگین است. */
    protected const DEFAULT_LIMIT = 500;

    protected const MAX_LIMIT = 5000;

    /** یک ساعت ارفاق روی expire: ربات‌ها تایم‌استمپ را رُند می‌کنند و نباید بابت no-op پول کم شود. */
    protected const EXPIRE_SLACK_SECONDS = 3600;

    /** یک گیگ ارفاق روی حجم، به همان دلیل. */
    protected const VOLUME_SLACK_BYTES = 1073741824;

    protected const BYTES_PER_GB = 1073741824;

    public function __construct(
        protected MarzbanInboundTagService $tags,
        protected MarzbanUserPresenter $presenter,
        protected AccountService $accounts,
        protected SubscriptionFeedService $feed,
        protected PackageCategoryService $categories,
        protected AccountBillingPackageService $billingPackages,
    ) {}

    /**
     * GET /api/users — باید بدون هیچ کوئری‌استرینگی جواب بدهد.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Account::query()
            ->ownedByHierarchy($request->user())
            ->with(['package', 'packageDuration', 'server', 'ownerSeller']);

        // is_string لازم است: کوئری‌استرینگ آرایه‌ای (status[]=...) با تبدیل به
        // رشته یک ErrorException می‌داد و پاسخ ۵۰۰ بی‌شکل به ربات می‌رفت.
        $status = $this->queryString($request, 'status');

        if ($status !== '') {
            $mapped = $this->panelStatusesFor($status);

            if ($mapped === null) {
                return MarzbanDetailResponse::make(__('marzban.unknown_status'), 422);
            }

            $query->whereIn('status', $mapped);
        }

        $search = $this->queryString($request, 'username');

        if ($search !== '') {
            $query->where('remote_username', 'like', '%'.$search.'%');
        }

        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query('limit', self::DEFAULT_LIMIT)));
        $offset = max(0, (int) $request->query('offset', 0));

        $total = (clone $query)->count();

        $accounts = $query->orderByDesc('id')->skip($offset)->take($limit)->get();

        return response()->json([
            'users' => $accounts->map(fn (Account $account): array => $this->presenter->present($account))->all(),
            'total' => $total,
        ]);
    }

    public function show(Request $request, string $username): JsonResponse
    {
        $account = $this->findAccount($request, $username);

        if ($account === null) {
            return $this->notFound();
        }

        return response()->json($this->presenter->present($account));
    }

    /**
     * POST /api/user — فروش جدید.
     *
     * پکیج و بازه از tag همان inbound که ربات پس می‌فرستد استخراج می‌شود؛
     * نگاشت در MarzbanInboundTagService توضیح داده شده است.
     */
    public function store(Request $request, ServerSelectionService $serverSelection): JsonResponse
    {
        /** @var User $agent */
        $agent = $request->user();
        $payload = $request->all();

        try {
            $username = AccountNameValidator::assertValid(trim((string) ($payload['username'] ?? '')));
        } catch (InvalidArgumentException $exception) {
            return MarzbanDetailResponse::make($exception->getMessage(), 422);
        }

        // شامل اکانت‌های حذف‌شدهٔ نرم، چون ایندکس یکتای remote_username آن‌ها را
        // هم می‌شمارد و درج بدون این بررسی با خطای دیتابیس می‌شکند.
        if (Account::withTrashed()->where('remote_username', $username)->exists()) {
            return MarzbanDetailResponse::make(__('marzban.username_taken'), 409);
        }

        $resolved = $this->tags->resolveFromInbounds($agent, $payload['inbounds'] ?? null);

        if ($resolved === null) {
            return MarzbanDetailResponse::make(__('marzban.inbound_tag_required'), 400);
        }

        [$package, $duration] = $resolved;

        if (! $this->tags->sellable($package)) {
            return MarzbanDetailResponse::make(__('marzban.package_not_sellable'), 422);
        }

        if (! $this->categories->isPackageAvailableForNewAccounts($package)) {
            return MarzbanDetailResponse::make(__('marzban.package_unavailable'), 422);
        }

        $clientData = [
            'client_mode' => 'new',
            // ربات مشتری پنل نمی‌سازد؛ فقط یک اکانت روی سرور می‌خواهد.
            'skip_portal_client' => true,
            'remote_username' => $username,
            'display_label' => $this->note($payload),
        ];

        // تصمیم طراحی: حجم و مدتِ ربات فقط برای پکیج حجمی (elastic) معنا دارد.
        // برای پکیج ثابت، حجم و مدت خودِ پکیج حاکم است و مقدار ربات نادیده
        // گرفته می‌شود، وگرنه پول یک پلن گرفته و پلن دیگری تحویل می‌شد.
        if ($package->isElastic()) {
            $bytes = (int) ($payload['data_limit'] ?? 0);

            if ($bytes > 0) {
                $clientData['data_gb'] = $package->clampDataGb($bytes / self::BYTES_PER_GB);
            }

            $expiry = $this->requestedExpiryForCreate($payload, $duration);

            if ($expiry !== null) {
                $clientData['custom_expiry_at'] = $expiry;
            }
        }

        try {
            $server = $serverSelection->pickLeastBusyForPackage($package);
        } catch (Throwable $exception) {
            Log::warning('Marzban facade create failed: no server', [
                'agent_id' => $agent->id,
                'package_id' => $package->id,
                'message' => $exception->getMessage(),
            ]);

            return MarzbanDetailResponse::make($exception->getMessage(), 422);
        }

        try {
            $account = $this->accounts->createAccount(
                $agent,
                $package,
                $server,
                $duration,
                $clientData,
                AccountBillingContext::Staff,
            );
        } catch (InsufficientWalletBalanceException $exception) {
            $this->logFailure('create', $agent, $username, $exception);

            return MarzbanDetailResponse::make(__('marzban.insufficient_balance'), 400);
        } catch (ValidationException $exception) {
            $this->logFailure('create', $agent, $username, $exception);

            return MarzbanDetailResponse::make($this->firstValidationMessage($exception), 422);
        } catch (InvalidArgumentException $exception) {
            $this->logFailure('create', $agent, $username, $exception);

            return MarzbanDetailResponse::make($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            report($exception);
            $this->logFailure('create', $agent, $username, $exception);

            return MarzbanDetailResponse::make(__('marzban.create_failed'), 500);
        }

        // مرزبان اجازهٔ ساختِ کاربر غیرفعال را می‌دهد؛ اگر ربات چنین خواست،
        // بعد از ساخت خاموش می‌شود (خرید و کسر کیف‌پول همان‌جا انجام شده است).
        if ($this->requestedStatus($payload) === 'disabled') {
            try {
                $account = $this->accounts->disableAccount($account);
            } catch (Throwable $exception) {
                report($exception);
                $this->logFailure('create.disable', $agent, $username, $exception);
            }
        }

        return response()->json($this->presenter->present($account));
    }

    /**
     * PUT /api/user/{username} — ربات‌ها کل شیء را پس می‌فرستند، نه فقط تغییرات.
     *
     * پس هر فیلد با وضعیت فعلی مقایسه می‌شود و تنها تفاوتِ معنادار اعمال
     * می‌گردد؛ وگرنه هر بار ذخیره‌ی ساده در ربات یک تمدید پولی می‌شد.
     */
    public function update(Request $request, string $username): JsonResponse
    {
        $account = $this->findAccount($request, $username);

        if ($account === null) {
            return $this->notFound();
        }

        $payload = $request->all();

        if (array_key_exists('note', $payload)) {
            $account->update(['display_label' => $this->note($payload)]);
        }

        $renewal = $this->applyRenewal($request, $account, $payload);

        if ($renewal instanceof JsonResponse) {
            return $renewal;
        }

        $account = $renewal;

        $statusResult = $this->applyStatus($account, $payload);

        if ($statusResult instanceof JsonResponse) {
            return $statusResult;
        }

        return response()->json($this->presenter->present($statusResult->refresh()));
    }

    public function destroy(Request $request, string $username): JsonResponse
    {
        $account = $this->findAccount($request, $username);

        if ($account === null) {
            return $this->notFound();
        }

        try {
            $this->accounts->deleteAccount($account, $request->user());
        } catch (Throwable $exception) {
            report($exception);
            $this->logFailure('delete', $request->user(), $username, $exception);

            return MarzbanDetailResponse::make(__('marzban.delete_failed'), 500);
        }

        // همان شکل پاسخ مرزبان برای حذف موفق.
        return response()->json(['detail' => __('marzban.user_deleted')]);
    }

    /**
     * POST /api/user/{username}/reset
     *
     * در مرزبان این یعنی «شمارندهٔ مصرف را صفر کن». پنل چنین کاری نمی‌کند: حجم
     * از عمده‌فروش خریداری شده و صفر کردن مصرف یعنی حجم نامحدودِ رایگان به قیمت
     * یک خرید — یک سوراخ درآمدی مستقیم برای صاحب پنل. پس این‌جا شمارنده‌ها از
     * سرور و لاگ‌های مصرف «تازه‌سازی» می‌شوند (که همان دردِ واقعی «عدد پنل با
     * خرید من نمی‌خواند» را درمان می‌کند) و حجمی هدیه داده نمی‌شود.
     *
     * تازه‌سازی پس از ارسال پاسخ اجرا می‌شود، چون یک تماس HTTP با پنل راه دور
     * است و ربات‌ها تایم‌اوت کوتاهی دارند؛ عدد به‌روز در اولین خواندن بعدی دیده
     * می‌شود.
     */
    public function reset(Request $request, string $username): JsonResponse
    {
        $account = $this->findAccount($request, $username);

        if ($account === null) {
            return $this->notFound();
        }

        Log::info('Marzban facade traffic reset requested', [
            'agent_id' => $request->user()?->id,
            'account_id' => $account->id,
            'username' => $account->remote_username,
            'note' => 'counters refreshed from panel; quota is never granted for free',
        ]);

        $accountId = (int) $account->id;

        dispatch(function () use ($accountId): void {
            $fresh = Account::query()->find($accountId);

            if ($fresh === null) {
                return;
            }

            try {
                app(AccountService::class)->refreshUsageFromPanelAndLogs($fresh);
            } catch (Throwable $exception) {
                report($exception);
            }
        })->afterResponse();

        return response()->json($this->presenter->present($account));
    }

    /**
     * POST /api/user/{username}/revoke_sub — چرخاندن توکن اشتراک.
     */
    public function revokeSub(Request $request, string $username): JsonResponse
    {
        $account = $this->findAccount($request, $username);

        if ($account === null) {
            return $this->notFound();
        }

        $this->feed->issue($account);

        return response()->json($this->presenter->present($account->refresh()));
    }

    /**
     * تمدید در صورت لزوم.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function applyRenewal(Request $request, Account $account, array $payload): Account|JsonResponse
    {
        $package = $account->package;
        $isElastic = $package instanceof Package && $package->isElastic();

        $now = now()->getTimestamp();
        $currentExpire = $account->expiry_at?->getTimestamp() ?? 0;
        $requestedExpire = $this->requestedExpire($payload);

        $wantsTime = $requestedExpire !== null
            && $requestedExpire > max($currentExpire, $now) + self::EXPIRE_SLACK_SECONDS;

        $currentLimit = (int) ($account->data_limit_bytes ?? 0);
        $requestedLimit = array_key_exists('data_limit', $payload) ? max(0, (int) $payload['data_limit']) : null;

        // ارتقای حجم فقط برای پکیج حجمی معنا دارد؛ در پکیج ثابت حجم را خودِ
        // پکیج تعیین می‌کند. اکانت نامحدود ($currentLimit === 0) هم چیزی برای
        // ارتقا ندارد.
        $wantsVolume = $isElastic
            && $requestedLimit !== null
            && $currentLimit > 0
            && $requestedLimit > $currentLimit + self::VOLUME_SLACK_BYTES;

        if (! $wantsTime && ! $wantsVolume) {
            return $account;
        }

        $duration = $this->resolveRenewalDuration($account, $wantsTime ? $requestedExpire : null);
        $mode = $wantsVolume ? 'upgrade_volume' : 'same';
        $gbOverride = $wantsVolume ? round($requestedLimit / self::BYTES_PER_GB, 2) : null;

        try {
            return $this->accounts->renewAccount(
                $account,
                $duration,
                AccountBillingContext::Staff,
                $request->user(),
                $gbOverride,
                $mode,
            );
        } catch (InsufficientWalletBalanceException $exception) {
            $this->logFailure('renew', $request->user(), (string) $account->remote_username, $exception);

            return MarzbanDetailResponse::make(__('marzban.insufficient_balance'), 400);
        } catch (InvalidArgumentException|ValidationException $exception) {
            $this->logFailure('renew', $request->user(), (string) $account->remote_username, $exception);

            return MarzbanDetailResponse::make(
                $exception instanceof ValidationException
                    ? $this->firstValidationMessage($exception)
                    : $exception->getMessage(),
                422,
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->logFailure('renew', $request->user(), (string) $account->remote_username, $exception);

            return MarzbanDetailResponse::make(__('marzban.renew_failed'), 500);
        }
    }

    /**
     * بازه‌ای که تمدید با آن قیمت می‌خورد.
     *
     * مرزبان تاریخ انقضای دلخواه می‌گیرد، ولی این‌جا باید پول همان مدت گرفته
     * شود؛ پس بلندترین بازهٔ فعالی انتخاب می‌شود که از مدت درخواستی «بیشتر
     * نباشد». این‌طور مشتری هرگز مدتی بیش از آنچه پرداخت شده نمی‌گیرد و اگر
     * بازهٔ دقیق (مثلاً شش‌ماهه) در پکیج تعریف شده باشد، همان انتخاب می‌شود.
     */
    protected function resolveRenewalDuration(Account $account, ?int $requestedExpire): ?PackageDuration
    {
        $billingPackage = $this->billingPackages->resolveBillingPackage($account);

        $durations = PackageDuration::query()
            ->where('package_id', $billingPackage->id)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->get();

        if ($durations->isEmpty()) {
            return null;
        }

        if ($requestedExpire === null) {
            // فقط ارتقای حجم؛ همان بازهٔ فعلی اگر به پکیج صورت‌حساب تعلق دارد.
            $current = $account->packageDuration;

            return $current !== null && (int) $current->package_id === (int) $billingPackage->id
                ? $current
                : $durations->first();
        }

        $base = max(now()->getTimestamp(), $account->expiry_at?->getTimestamp() ?? 0);
        $requestedHours = max(0, (int) floor(($requestedExpire - $base) / 3600));

        $best = null;

        foreach ($durations as $duration) {
            $hours = $duration->tier->durationHours();

            // بازهٔ بی‌انقضا هیچ‌وقت برای «تا تاریخ مشخص» انتخاب نمی‌شود.
            if ($hours === null || $hours > $requestedHours) {
                continue;
            }

            if ($best === null || $hours > $best->tier->durationHours()) {
                $best = $duration;
            }
        }

        return $best ?? $durations->first();
    }

    /**
     * روشن/خاموش کردن اکانت طبق فیلد status.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function applyStatus(Account $account, array $payload): Account|JsonResponse
    {
        $requested = $this->requestedStatus($payload);

        if ($requested === null) {
            return $account;
        }

        try {
            if ($requested === 'disabled' && $account->status === AccountStatus::Active) {
                return $this->accounts->disableAccount($account);
            }

            // on_hold در پنل وجود ندارد؛ نزدیک‌ترین معنا «فعال» است.
            if (in_array($requested, ['active', 'on_hold'], true) && $account->status === AccountStatus::Disabled) {
                return $this->accounts->enableAccount($account);
            }
        } catch (InvalidArgumentException $exception) {
            // مثلاً فعال‌کردن اکانت منقضی یا تمام‌حجم؛ پیام سرویس گویاست.
            $this->logFailure('status', $account->ownerSeller, (string) $account->remote_username, $exception);

            return MarzbanDetailResponse::make($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            report($exception);
            $this->logFailure('status', $account->ownerSeller, (string) $account->remote_username, $exception);

            return MarzbanDetailResponse::make(__('marzban.status_failed'), 500);
        }

        return $account;
    }

    /**
     * نام کاربری درصد-انکود می‌رسد (ربات‌ها urlencode می‌کنند).
     */
    protected function findAccount(Request $request, string $username): ?Account
    {
        $decoded = trim(rawurldecode($username));

        if ($decoded === '') {
            return null;
        }

        return Account::query()
            ->ownedByHierarchy($request->user())
            ->where('remote_username', $decoded)
            ->with(['package', 'packageDuration', 'server', 'ownerSeller'])
            ->first();
    }

    /**
     * پارامتر کوئری فقط اگر واقعاً رشته باشد خوانده می‌شود.
     */
    protected function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? trim($value) : '';
    }

    protected function notFound(): JsonResponse
    {
        // متن مرزبان؛ ربات‌ها روی همین رشته شرط گذاشته‌اند.
        return MarzbanDetailResponse::make('User not found', 404);
    }

    /**
     * انقضای درخواستی برای ساخت، محدود به سقف خودِ بازهٔ خریداری‌شده.
     *
     * بدون این سقف، ربات می‌توانست با پرداخت یک‌ماهه، تاریخ انقضای یک‌ساله
     * بفرستد.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function requestedExpiryForCreate(array $payload, PackageDuration $duration): ?Carbon
    {
        $timestamp = $this->requestedExpire($payload);

        if ($timestamp === null || $timestamp <= now()->getTimestamp()) {
            return null;
        }

        $requested = Carbon::createFromTimestamp($timestamp);
        $ceiling = $duration->expiryFromNow();

        return $ceiling !== null && $requested->greaterThan($ceiling) ? $ceiling : $requested;
    }

    /**
     * تایم‌استمپ انقضا از بدنه؛ حالت on_hold میرزا هم پشتیبانی می‌شود، چون
     * expire نمی‌فرستد و مدت را به ثانیه در on_hold_expire_duration می‌گذارد.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function requestedExpire(array $payload): ?int
    {
        $raw = $payload['expire'] ?? null;

        if (is_numeric($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        $hold = $payload['on_hold_expire_duration'] ?? null;

        if (is_numeric($hold) && (int) $hold > 0) {
            return now()->getTimestamp() + (int) $hold;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function requestedStatus(array $payload): ?string
    {
        $status = strtolower(trim((string) ($payload['status'] ?? '')));

        return $status === '' ? null : $status;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function note(array $payload): ?string
    {
        $note = trim((string) ($payload['note'] ?? ''));

        return $note === '' ? null : mb_substr($note, 0, 255);
    }

    /**
     * وضعیت مرزبان به وضعیت‌های پنل. null یعنی مقدار ناشناس.
     *
     * @return list<string>|null
     */
    protected function panelStatusesFor(string $status): ?array
    {
        return match (strtolower($status)) {
            'active' => [AccountStatus::Active->value],
            // اکانت Pending هنوز روی سرور ساخته نشده، پس در دستهٔ «غیرفعال».
            'disabled' => [AccountStatus::Disabled->value, AccountStatus::Pending->value],
            'limited' => [AccountStatus::Exhausted->value],
            'expired' => [AccountStatus::Expired->value],
            // پنل حالت انتظار ندارد؛ فهرست خالی، نه خطا.
            'on_hold' => [],
            default => null,
        };
    }

    protected function firstValidationMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            foreach ((array) $messages as $message) {
                return (string) $message;
            }
        }

        return $exception->getMessage();
    }

    /**
     * ویزویز در مسیرهای به‌روزرسانی و ریست، isset($response->detail) را روی یک
     * رشتهٔ خام تست می‌کند، پس خطای ۴xx ما را «موفق» می‌خواند و هیچ‌وقت به
     * فروشنده نشان نمی‌دهد. تنها جای قابل اعتماد برای دیدن این شکست‌ها لاگِ
     * خودِ پنل است، پس همه‌شان این‌جا ثبت می‌شوند.
     */
    protected function logFailure(string $operation, ?User $agent, string $username, Throwable $exception): void
    {
        Log::warning('Marzban facade operation failed', [
            'operation' => $operation,
            'agent_id' => $agent?->id,
            'agent_username' => $agent?->username,
            'account_username' => $username,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
