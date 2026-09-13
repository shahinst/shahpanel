<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ServerType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Re-enable vpnpanel accounts marked Exhausted/Disabled when Pasarguard/Remnawave
 * still reports remaining quota. Does not change volume limits or reset traffic.
 */
class AccountMisdisabledRepairService
{
    private const REMAINING_TOLERANCE_BYTES = 1024 * 1024;

    public function __construct(
        protected PasarguardService $pasarguardService,
        protected RemnawaveService $remnawaveService,
        protected AccountService $accountService,
    ) {}

    /**
     * @return Collection<int, Account>
     */
    public function findCandidates(?int $accountId = null): Collection
    {
        $query = Account::query()
            ->whereNull('refunded_at')
            ->whereIn('status', [AccountStatus::Exhausted, AccountStatus::Disabled])
            ->where(function (Builder $query): void {
                $query->whereNull('expiry_at')
                    ->orWhere('expiry_at', '>', now());
            })
            ->where(function (Builder $query): void {
                $query->whereHas('server', function (Builder $server): void {
                    $server->whereIn('type', [
                        ServerType::Pasarguard->value,
                        ServerType::Remnawave->value,
                    ]);
                })
                    ->orWhereNotNull('pasarguard_user_id')
                    ->orWhereNotNull('remnawave_uuid');
            })
            ->with(['server', 'package', 'packageDuration']);

        if ($accountId !== null) {
            $query->whereKey($accountId);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * @return array{
     *     ok: bool,
     *     eligible: bool,
     *     remote_remaining_bytes: ?int,
     *     remote_limit_bytes: ?int,
     *     remote_used_bytes: ?int,
     *     detail: string
     * }
     */
    public function inspect(Account $account): array
    {
        $account->loadMissing(['server', 'package']);

        if ($account->isExpired()) {
            return $this->result(false, false, null, null, null, 'اکانت منقضی شده است.');
        }

        if (! in_array($account->status, [AccountStatus::Exhausted, AccountStatus::Disabled], true)) {
            return $this->result(true, false, null, null, null, 'وضعیت محلی فعال است — نیازی به اصلاح نیست.');
        }

        try {
            $snapshot = $this->remoteTrafficSnapshot($account);
        } catch (Throwable $exception) {
            return $this->result(false, false, null, null, null, 'خطا در خواندن پنل: '.$exception->getMessage());
        }

        if ($snapshot === null) {
            return $this->result(true, false, null, null, null, 'فقط Pasarguard/Remnawave پشتیبانی می‌شود.');
        }

        $limit = $snapshot['limit_bytes'];
        $used = $snapshot['used_bytes'];
        $remaining = $snapshot['remaining_bytes'];

        if ($limit !== null && $limit > 0 && $remaining !== null && $remaining <= self::REMAINING_TOLERANCE_BYTES) {
            return $this->result(true, false, $remaining, $limit, $used, 'حجم پنل واقعاً تمام شده ('.format_data_size($used).' / '.format_data_size($limit).').');
        }

        $remainingLabel = $remaining !== null
            ? format_data_size($remaining)
            : __('accounts.unlimited_data');

        $statusLabel = match ($account->status) {
            AccountStatus::Exhausted => __('accounts.status_exhausted'),
            AccountStatus::Disabled => __('accounts.status_disabled'),
            default => $account->status->value,
        };

        return $this->result(
            true,
            true,
            $remaining,
            $limit,
            $used,
            'پنل هنوز '.$remainingLabel.' باقی دارد؛ vpnpanel «'.$statusLabel.'» است.',
        );
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function repair(Account $account): array
    {
        $inspection = $this->inspect($account);

        if (! ($inspection['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($inspection['detail'] ?? 'خطای بررسی')];
        }

        if (! ($inspection['eligible'] ?? false)) {
            return ['ok' => true, 'message' => (string) ($inspection['detail'] ?? 'نیازی به اصلاح نیست')];
        }

        $account->loadMissing(['server', 'package', 'packageDuration']);

        try {
            $this->accountService->syncUsedBytesFromRemotePanel($account);
            $account->refresh();

            $account->update(['status' => AccountStatus::Active]);
            $this->accountService->enablePanelAccountWithoutQuotaChanges($account->fresh());
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => 'فعال‌سازی ناموفق: '.$exception->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'message' => 'اکانت #'.$account->id.' ('.$account->remote_username.') دوباره فعال شد (بدون تغییر سقف حجم).',
        ];
    }

    /**
     * @return array{used_bytes: int, limit_bytes: ?int, remaining_bytes: ?int}|null
     */
    protected function remoteTrafficSnapshot(Account $account): ?array
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null) {
            throw new \RuntimeException('اکانت بدون سرور است.');
        }

        if ($server->isPasarguard() || $account->service_type->isPasarguard() || $account->pasarguard_user_id) {
            $remote = $this->pasarguardService->getUser($server, $account->remote_username);

            return $this->pasarguardService->normalizeTrafficSnapshot($remote);
        }

        if ($server->isRemnawave() || $account->service_type->isRemnawave() || $account->remnawave_uuid) {
            $remote = $account->remnawave_uuid
                ? $this->remnawaveService->getUserByUuid($server, (string) $account->remnawave_uuid)
                : $this->remnawaveService->getUser($server, $account->remote_username);

            if ($remote === null) {
                throw new \RuntimeException('کاربر Remnawave روی پنل یافت نشد.');
            }

            return $this->remnawaveService->normalizeTrafficSnapshot($remote);
        }

        return null;
    }

    /**
     * @return array{
     *     ok: bool,
     *     eligible: bool,
     *     remote_remaining_bytes: ?int,
     *     remote_limit_bytes: ?int,
     *     remote_used_bytes: ?int,
     *     detail: string
     * }
     */
    protected function result(
        bool $ok,
        bool $eligible,
        ?int $remaining,
        ?int $limit,
        ?int $used,
        string $detail,
    ): array {
        return [
            'ok' => $ok,
            'eligible' => $eligible,
            'remote_remaining_bytes' => $remaining,
            'remote_limit_bytes' => $limit,
            'remote_used_bytes' => $used,
            'detail' => $detail,
        ];
    }
}
