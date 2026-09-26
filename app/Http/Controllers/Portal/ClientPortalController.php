<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceType;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ClientPortalView;
use App\Models\Invoice;
use App\Services\AccountService;
use App\Services\LoginCaptchaService;
use App\Services\PortalPanelTrafficService;
use App\Services\PortalLinkService;
use App\Services\SanaeiPortalService;
use App\Services\SyncService;
use App\Services\WireGuardConfigService;
use App\Support\PortalCaptchaGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\View\View;
use Throwable;

class ClientPortalController extends Controller
{
    public function show(
        Request $request,
        string $token,
        WireGuardConfigService $configService,
        SanaeiPortalService $sanaeiPortalService,
        SyncService $syncService
    ): View {
        $account = $this->findAccount($token);

        // کپچا دروازهٔ «داده» است نه دروازهٔ «نمایش»: تا جواب درست نیامده باشد
        // هیچ چیزی از حساب ساخته نمی‌شود، پس نه در سورس صفحه هست و نه در اسکریپت.
        if (! PortalCaptchaGate::isSolved($request, $token)) {
            return $this->challengeView($request, $token);
        }

        ClientPortalView::query()->create([
            'account_id' => $account->id,
            'viewed_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        $portalSnapshot = $this->buildPortalSnapshot($account, $syncService, syncOnOpen: true);

        $invoice = Invoice::query()
            ->where('account_id', $account->id)
            ->where('type', InvoiceType::NewAccount)
            ->orderBy('issued_at')
            ->first();

        $config = null;
        $qrBase64 = null;
        $sanaei = [
            'subscription_link' => null,
            'subscription_qr' => null,
        ];

        if ($account->service_type === ServiceType::Wireguard) {
            try {
                $config = $configService->buildConfig($account);
                $qrBase64 = base64_encode($configService->buildQrPng($account));
            } catch (\Throwable) {
                // config shown only when available
            }
        } elseif ($account->service_type->isPanelV2ray()) {
            $sanaei = $sanaeiPortalService->portalAssets($account);
        }

        $portalCustomization = app(\App\Services\PortalCustomizationService::class);
        $portalAnnouncements = $portalCustomization->announcements();
        $portalAppCategories = $portalCustomization->suggestedAppCategories();
        $isWireguard = $account->service_type === ServiceType::Wireguard;

        return view('portal.client', compact(
            'account',
            'invoice',
            'config',
            'qrBase64',
            'sanaei',
            'portalAnnouncements',
            'portalAppCategories',
            'portalSnapshot',
            'isWireguard',
        ));
    }

    public function downloadConfig(Request $request, string $token, WireGuardConfigService $configService): Response
    {
        $account = $this->findAccount($token);
        $this->assertCaptchaSolved($request, $token);
        abort_unless($account->service_type === ServiceType::Wireguard, 404);

        $config = $configService->buildConfig($account);

        return response($config, 200, [
            'Content-Type' => 'application/x-wireguard-profile',
            'Content-Disposition' => 'attachment; filename="'.$configService->configFilename($account).'"',
        ]);
    }

    public function downloadQr(Request $request, string $token, WireGuardConfigService $configService): Response
    {
        $account = $this->findAccount($token);
        $this->assertCaptchaSolved($request, $token);
        abort_unless($account->service_type === ServiceType::Wireguard, 404);

        return $configService->qrDownloadResponse($account);
    }

    public function stats(Request $request, string $token, SyncService $syncService): JsonResponse
    {
        $account = $this->findAccount($token);

        // آمار مصرف هم دادهٔ حساب است؛ بدون کپچا پاسخی نمی‌گیرد.
        if (! PortalCaptchaGate::isSolved($request, $token)) {
            return response()->json(['message' => __('accounts.portal_captcha_required')], 403);
        }

        $snapshot = $this->buildPortalSnapshot($account, $syncService, syncOnOpen: true);

        return response()->json($snapshot);
    }

    /** کپچای تازه برای دکمهٔ «تصویر دیگر» صفحهٔ مشتری. */
    public function captcha(Request $request, string $token, LoginCaptchaService $captcha): JsonResponse
    {
        // توکن نامعتبر یا منقضی حتی کپچا هم نمی‌گیرد.
        $this->findAccount($token);

        return response()->json($captcha->issue($request));
    }

    /**
     * بررسی جواب کپچا. اعتبارسنجی کامل سمت سرور است و توکن کپچا یک‌بارمصرف
     * (pull از سشن)؛ پس جواب درست را نمی‌شود دوباره فرستاد. بعد از تأیید هم
     * ریدایرکت می‌کنیم تا داده در یک درخواست *بعدی* برسد.
     */
    public function verify(Request $request, string $token, LoginCaptchaService $captcha): RedirectResponse
    {
        $this->findAccount($token);

        $captcha->assertValid(
            $request,
            $request->input('captcha'),
            $request->input('captcha_token'),
        );

        PortalCaptchaGate::markSolved($request, $token);

        return redirect()->route('portal.show', $token);
    }

    /**
     * صفحهٔ کپچا: هیچ متغیری از حساب به این ویو نمی‌رود — نه نام کاربری، نه
     * لینک اشتراک، نه کانفیگ.
     */
    protected function challengeView(Request $request, string $token): View
    {
        return view('portal.challenge', [
            'portalToken' => $token,
            'captcha' => app(LoginCaptchaService::class)->issue($request),
        ]);
    }

    /** دانلودها هم پشت همین دروازه‌اند، وگرنه کپچا دور زدنی بود. */
    protected function assertCaptchaSolved(Request $request, string $token): void
    {
        if (! PortalCaptchaGate::isSolved($request, $token)) {
            throw new HttpResponseException(redirect()->route('portal.show', $token));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPortalSnapshot(Account $account, SyncService $syncService, bool $syncOnOpen = false): array
    {
        if ($syncOnOpen) {
            $this->syncAccountOnPortalOpen($account, $syncService);
            $account->refresh();
        }

        $trafficMeta = app(PortalPanelTrafficService::class)->fetchLiveTraffic($account)
            ?? $syncService->consumeLastPortalTrafficMeta();
        $usage = $this->resolvePortalUsage($account, $trafficMeta);
        $connection = $this->resolveConnectionStatus($account, $trafficMeta, 0);

        return [
            'status' => $account->status->value,
            'status_label' => $this->statusLabel($account),
            'connection' => $connection['state'],
            'connection_label' => $connection['label'],
            'data_used_bytes' => $usage['used_bytes'],
            'data_used_human' => format_data_size($usage['used_bytes']),
            'lifetime_used_bytes' => $usage['lifetime_bytes'],
            'lifetime_used_human' => format_data_size($usage['lifetime_bytes']),
            'data_limit_bytes' => $usage['limit_bytes'],
            'data_limit_human' => $usage['limit_bytes'] !== null ? format_data_size($usage['limit_bytes']) : null,
            'download_used_bytes' => $usage['download_bytes'],
            'download_used_human' => format_data_size($usage['download_bytes']),
            'upload_used_bytes' => $usage['upload_bytes'],
            'upload_used_human' => format_data_size($usage['upload_bytes']),
            'remaining_bytes' => $usage['remaining_bytes'],
            'remaining_human' => $usage['remaining_bytes'] !== null ? format_data_size($usage['remaining_bytes']) : null,
            'usage_percent' => $usage['usage_percent'],
            'expiry_at' => $account->expiry_at?->toIso8601String(),
            'expiry_human' => $account->expiry_at ? jalali_date($account->expiry_at, 'Y/m/d') : __('accounts.no_expiry'),
            'last_sync_at' => $account->last_sync_at?->toIso8601String(),
            'last_sync_human' => $account->last_sync_at ? jalali_date($account->last_sync_at, 'Y/m/d H:i') : null,
            'is_expired' => $account->isExpired(),
            'is_unlimited' => $account->isUnlimited(),
        ];
    }

    protected function syncAccountOnPortalOpen(Account $account, SyncService $syncService): void
    {
        if ($account->server === null || $account->status->value !== 'active') {
            return;
        }

        try {
            $syncService->syncAccount($account);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array{raw: array<string, mixed>|null, normalized: array<string, int|null>}|null  $trafficMeta
     * @return array{
     *     used_bytes: int,
     *     lifetime_bytes: int,
     *     download_bytes: int,
     *     upload_bytes: int,
     *     limit_bytes: ?int,
     *     remaining_bytes: ?int,
     *     usage_percent: ?float
     * }
     */
    protected function resolvePortalUsage(Account $account, ?array $trafficMeta): array
    {
        $accountService = app(AccountService::class);
        $normalized = is_array($trafficMeta['normalized'] ?? null) ? $trafficMeta['normalized'] : null;
        $hasLivePanel = $accountService->readsUsageFromRemotePanel($account)
            && is_array($trafficMeta['raw'] ?? null);

        if ($hasLivePanel && $normalized !== null) {
            $usedBytes = max(0, (int) ($normalized['used_bytes'] ?? 0));
            // Panel lifetime counter ("ترافیک کل") — the real total a customer recognises,
            // independent of per-period resets. Never below the current period figure.
            $lifetimeBytes = max($usedBytes, (int) ($normalized['lifetime_used_bytes'] ?? $usedBytes));
            $limitBytes = isset($normalized['limit_bytes']) && (int) $normalized['limit_bytes'] > 0
                ? (int) $normalized['limit_bytes']
                : null;
            $remainingBytes = $limitBytes !== null
                ? max(0, $limitBytes - $usedBytes)
                : null;

            $downloadBytes = (int) ($normalized['download_bytes'] ?? $normalized['down'] ?? $normalized['rx_bytes'] ?? 0);
            $uploadBytes = (int) ($normalized['upload_bytes'] ?? $normalized['up'] ?? $normalized['tx_bytes'] ?? 0);

            if ($downloadBytes === 0 && $uploadBytes === 0 && $usedBytes > 0) {
                $downloadBytes = $usedBytes;
            }

            $usagePercent = null;
            if ($limitBytes !== null && $limitBytes > 0) {
                $usagePercent = min(100, round(($usedBytes / $limitBytes) * 100, 1));
            }

            return [
                'used_bytes' => $usedBytes,
                'lifetime_bytes' => $lifetimeBytes,
                'download_bytes' => $downloadBytes,
                'upload_bytes' => $uploadBytes,
                'limit_bytes' => $limitBytes,
                'remaining_bytes' => $remainingBytes,
                'usage_percent' => $usagePercent,
            ];
        }

        $usedBytes = max(0, (int) $account->data_used_bytes);
        $limitBytes = $account->isUnlimited() ? null : max(0, (int) $account->data_limit_bytes);
        $remainingBytes = $limitBytes !== null ? max(0, $limitBytes - $usedBytes) : null;

        $downloadBytes = 0;
        $uploadBytes = 0;
        // Lifetime mirrored from the panel on each cron sync; never below current period.
        $lifetimeBytes = max($usedBytes, (int) ($account->lifetime_used_bytes ?? 0));

        if ($normalized !== null) {
            $downloadBytes = (int) ($normalized['download_bytes'] ?? $normalized['down'] ?? $normalized['rx_bytes'] ?? 0);
            $uploadBytes = (int) ($normalized['upload_bytes'] ?? $normalized['up'] ?? $normalized['tx_bytes'] ?? 0);
            $lifetimeBytes = max($lifetimeBytes, (int) ($normalized['lifetime_used_bytes'] ?? 0));
        }

        if ($downloadBytes === 0 && $uploadBytes === 0 && $usedBytes > 0) {
            $downloadBytes = $usedBytes;
        }

        $usagePercent = null;
        if ($limitBytes !== null && $limitBytes > 0) {
            $usagePercent = min(100, round(($usedBytes / $limitBytes) * 100, 1));
        }

        return [
            'used_bytes' => $usedBytes,
            'lifetime_bytes' => $lifetimeBytes,
            'download_bytes' => $downloadBytes,
            'upload_bytes' => $uploadBytes,
            'limit_bytes' => $limitBytes,
            'remaining_bytes' => $remainingBytes,
            'usage_percent' => $usagePercent,
        ];
    }

    /**
     * @param  array{raw: array<string, mixed>|null, normalized: array<string, int|null>}|null  $trafficMeta
     * @return array{state: string, label: string}
     */
    protected function resolveConnectionStatus(Account $account, ?array $trafficMeta, int $recentDeltaBytes): array
    {
        if ($account->status->value !== 'active' || $account->isRefunded()) {
            return [
                'state' => 'inactive',
                'label' => __('accounts.connection_inactive'),
            ];
        }

        if ($recentDeltaBytes > 0) {
            return [
                'state' => 'online',
                'label' => __('accounts.connection_active'),
            ];
        }

        $raw = $trafficMeta['raw'] ?? null;

        if (is_array($raw)) {
            $lastOnline = (int) ($raw['lastOnline'] ?? $raw['last_online'] ?? 0);

            if ($lastOnline > 9_999_999_999) {
                $lastOnline = (int) floor($lastOnline / 1000);
            }

            if ($lastOnline > 0 && (time() - $lastOnline) <= 120) {
                return [
                    'state' => 'online',
                    'label' => __('accounts.connection_online'),
                ];
            }
        }

        return [
            'state' => 'offline',
            'label' => __('accounts.connection_offline'),
        ];
    }

    protected function statusLabel(Account $account): string
    {
        if ($account->isRefunded()) {
            return __('accounts.status_refunded');
        }

        return match ($account->status->value) {
            'active' => __('accounts.status_active'),
            'disabled' => __('accounts.status_disabled'),
            'expired' => __('accounts.status_expired'),
            'exhausted' => __('accounts.status_exhausted'),
            default => $account->status->value,
        };
    }

    protected function findAccount(string $token, ?PortalLinkService $portalLinks = null): Account
    {
        $account = Account::query()
            ->where('portal_token', $token)
            ->with(['package', 'packageDuration', 'server', 'ownerSeller.storefront'])
            ->first();

        if ($account === null) {
            abort(404);
        }

        $portalLinks ??= app(PortalLinkService::class);

        if (! $portalLinks->isActive($account)) {
            throw new HttpResponseException(
                response()->view('portal.expired', status: 410)
            );
        }

        return $account;
    }
}
