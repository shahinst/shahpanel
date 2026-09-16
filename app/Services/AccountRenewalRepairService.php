<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\InvoiceType;
use App\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Detects and repairs panel quota mismatches after renewals on inactive packages
 * (Pasarguard/Remnawave used_traffic not reset while shahpanel DB was renewed).
 */
class AccountRenewalRepairService
{
    public function __construct(
        protected PasarguardService $pasarguardService,
        protected RemnawaveService $remnawaveService,
        protected PackageCategoryService $packageCategoryService,
        protected AccountService $accountService,
    ) {}

    /**
     * @return Collection<int, Account>
     */
    public function findRenewalsOnInactivePackages(?int $withinDays = null): Collection
    {
        $query = Account::query()
            ->whereNull('refunded_at')
            ->whereHas('invoices', fn ($q) => $q->where('type', InvoiceType::Renewal))
            ->with(['package.category', 'server', 'invoices' => fn ($q) => $q->where('type', InvoiceType::Renewal)->orderByDesc('issued_at')]);

        if ($withinDays !== null && $withinDays > 0) {
            $since = Carbon::now()->subDays($withinDays);
            $query->whereHas('invoices', fn ($q) => $q
                ->where('type', InvoiceType::Renewal)
                ->where('issued_at', '>=', $since));
        }

        return $query->get()->filter(function (Account $account): bool {
            $package = $account->package;

            return $package !== null
                && ! $this->packageCategoryService->isPackageAvailableForRenewal($package);
        })->values();
    }

    /**
     * @return array{ok: bool, mismatch: bool, local_remaining_bytes: ?int, remote_remaining_bytes: ?int, detail: string}
     */
    public function inspectPanelQuota(Account $account): array
    {
        $account->loadMissing(['server', 'package']);

        if ($account->server === null || $account->isUnlimited()) {
            return [
                'ok' => true,
                'mismatch' => false,
                'local_remaining_bytes' => null,
                'remote_remaining_bytes' => null,
                'detail' => __('services.quota_no_cap_or_server'),
            ];
        }

        try {
            $snapshot = $this->remoteTrafficSnapshot($account);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'mismatch' => false,
                'local_remaining_bytes' => null,
                'remote_remaining_bytes' => null,
                'detail' => __('services.panel_read_error', ['error' => $exception->getMessage()]),
            ];
        }

        if ($snapshot === null) {
            return [
                'ok' => true,
                'mismatch' => false,
                'local_remaining_bytes' => null,
                'remote_remaining_bytes' => null,
                'detail' => __('services.quota_service_type_unsupported'),
            ];
        }

        $localLimit = (int) ($account->data_limit_bytes ?? 0);
        $localUsed = (int) $account->data_used_bytes;
        $localRemaining = max(0, $localLimit - $localUsed);

        $remoteLimit = $snapshot['limit_bytes'];
        $remoteUsed = $snapshot['used_bytes'];

        if ($remoteLimit === null || $remoteLimit <= 0) {
            return [
                'ok' => true,
                'mismatch' => false,
                'local_remaining_bytes' => $localRemaining,
                'remote_remaining_bytes' => null,
                'detail' => __('services.quota_panel_unlimited'),
            ];
        }

        $remoteRemaining = max(0, $remoteLimit - $remoteUsed);
        $tolerance = 50 * 1024 * 1024;
        $limitMismatch = abs($localLimit - $remoteLimit) > $tolerance;
        $remainingMismatch = $localRemaining > $tolerance && $remoteRemaining <= $tolerance;
        $mismatch = $limitMismatch || $remainingMismatch;

        return [
            'ok' => true,
            'mismatch' => $mismatch,
            'local_remaining_bytes' => $localRemaining,
            'remote_remaining_bytes' => $remoteRemaining,
            'detail' => $mismatch
                ? ($limitMismatch
                    ? __('services.quota_limit_differs', ['remote' => format_data_size($remoteLimit), 'local' => format_data_size($localLimit)])
                    : __('services.quota_panel_exhausted_local_remaining', ['remaining' => format_data_size($localRemaining)]))
                : __('services.quota_looks_consistent'),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function repair(Account $account): array
    {
        $account->loadMissing(['server', 'package', 'packageDuration']);

        if ($account->server === null) {
            return ['ok' => false, 'message' => __('services.account_no_server')];
        }

        $inspection = $this->inspectPanelQuota($account);

        if (! ($inspection['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($inspection['detail'] ?? __('services.inspection_error'))];
        }

        if (! ($inspection['mismatch'] ?? false)) {
            return ['ok' => true, 'message' => __('services.account_panel_repair_not_needed', ['detail' => $inspection['detail']])];
        }

        $updates = [];

        if ($account->status === AccountStatus::Exhausted) {
            $updates['status'] = AccountStatus::Active;
        }

        if ($updates !== []) {
            $account->update($updates);
            $account->refresh();
        }

        try {
            $this->accountService->ensurePanelQuotaMatchesDatabase($account->fresh(), resetTrafficIfNeeded: true);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => __('services.account_panel_repair_failed', ['error' => $exception->getMessage()]),
            ];
        }

        return [
            'ok' => true,
            'message' => __('services.account_panel_data_aligned'),
        ];
    }

    /**
     * @return array{used_bytes: int, limit_bytes: ?int}|null
     */
    protected function remoteTrafficSnapshot(Account $account): ?array
    {
        $server = $account->server;

        if ($server === null) {
            return null;
        }

        if ($server->isPasarguard() || $account->service_type->isPasarguard() || $account->pasarguard_user_id) {
            $remote = $this->pasarguardService->getUser($server, $account->remote_username);

            return $this->pasarguardService->normalizeTrafficSnapshot($remote);
        }

        if ($server->isRemnawave() || $account->service_type->isRemnawave() || $account->remnawave_uuid) {
            $remote = $this->remnawaveService->getUser($server, $account->remote_username);

            if ($remote === null) {
                throw new \RuntimeException(__('services.remnawave_user_not_found'));
            }

            return $this->remnawaveService->normalizeTrafficSnapshot($remote);
        }

        return null;
    }
}
