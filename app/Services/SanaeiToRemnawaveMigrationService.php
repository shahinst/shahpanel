<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerMigration;
use App\Models\ServerMigrationEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * انتقال اکانت‌های Sanaei از دیتابیس shahpanel به سرور Remnawave (بدون خواندن از پنل ثنایی).
 */
class SanaeiToRemnawaveMigrationService
{
    public function __construct(
        protected AccountService $accountService,
        protected RemnawaveService $remnawaveService,
        protected ActivityLogService $activityLogService,
        protected AccountBillingPackageService $billingPackageService,
    ) {}

    /**
     * @param  list<int>|null  $accountIds
     */
    public function run(
        Server $fromServer,
        Server $toServer,
        ?array $accountIds,
        User $actor,
        bool $dryRun = false,
        bool $tryDisableOnSource = false,
        ?int $targetPackageId = null,
    ): ServerMigration {
        $this->assertPair($fromServer, $toServer);

        $targetPackage = $this->resolveTargetPackage($toServer, $targetPackageId);

        $accounts = $this->resolveAccounts($fromServer, $accountIds);

        $migration = ServerMigration::query()->create([
            'user_id' => $actor->id,
            'from_server_id' => $fromServer->id,
            'to_server_id' => $toServer->id,
            'status' => 'running',
            'total_accounts' => $accounts->count(),
            'account_ids' => $accountIds,
            'dry_run' => $dryRun,
            'started_at' => now(),
        ]);

        $migrated = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($accounts as $account) {
            $entry = $this->migrateOne(
                $migration,
                $account,
                $fromServer,
                $toServer,
                $actor,
                $dryRun,
                $tryDisableOnSource,
                $targetPackage,
            );

            match ($entry->status) {
                'success' => $migrated++,
                'skipped' => $skipped++,
                default => $failed++,
            };
        }

        $summary = "انتقال از «{$fromServer->name}» به «{$toServer->name}»: "
            ."{$migrated} موفق، {$failed} خطا، {$skipped} رد شده (از {$accounts->count()}).";

        $migration->update([
            'status' => $failed > 0 && $migrated === 0 ? 'failed' : 'completed',
            'migrated_count' => $migrated,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'summary' => $summary,
            'completed_at' => now(),
        ]);

        return $migration->fresh(['fromServer', 'toServer', 'user']);
    }

    /**
     * @param  list<int>|null  $accountIds
     * @return Collection<int, Account>
     */
    public function resolveAccounts(Server $fromServer, ?array $accountIds): Collection
    {
        $query = Account::query()
            ->where('server_id', $fromServer->id)
            ->whereNull('refunded_at')
            ->whereNotIn('status', [AccountStatus::Pending])
            ->where(function ($q): void {
                $q->whereIn('service_type', [
                    ServiceType::SanaeiVmess,
                    ServiceType::SanaeiVless,
                    ServiceType::SanaeiTrojan,
                ])->orWhereNotNull('sanaei_client_uuid');
            })
            ->orderBy('id');

        if ($accountIds !== null && $accountIds !== []) {
            $query->whereIn('id', $accountIds);
        }

        return $query->get();
    }

    protected function migrateOne(
        ServerMigration $migration,
        Account $account,
        Server $fromServer,
        Server $toServer,
        User $actor,
        bool $dryRun,
        bool $tryDisableOnSource,
        ?Package $targetPackage = null,
    ): ServerMigrationEntry {
        $base = [
            'server_migration_id' => $migration->id,
            'account_id' => $account->id,
            'remote_username' => $account->remote_username,
            'data_limit_bytes' => $account->data_limit_bytes,
            'data_used_bytes' => $account->data_used_bytes,
            'expiry_at' => $account->expiry_at,
        ];

        if ($dryRun) {
            return ServerMigrationEntry::query()->create(array_merge($base, [
                'status' => 'preview',
                'message' => $this->describeDryRun($account),
            ]));
        }

        try {
            return DB::transaction(function () use (
                $migration,
                $account,
                $fromServer,
                $toServer,
                $actor,
                $tryDisableOnSource,
                $targetPackage,
                $base,
            ): ServerMigrationEntry {
                $account = $account->fresh(['package', 'packageDuration', 'server']);

                if ($tryDisableOnSource) {
                    $this->tryDisableOnSourceServer($account, $fromServer);
                }

                $oldServerId = (int) $account->server_id;
                $oldPackageId = (int) $account->package_id;

                $account->update([
                    'server_id' => $toServer->id,
                    'service_type' => ServiceType::Remnawave,
                    'sanaei_inbound_id' => null,
                    'sanaei_client_uuid' => null,
                    'sanaei_sub_id' => null,
                    'remnawave_uuid' => null,
                    'remnawave_subscription_url' => null,
                ]);

                $account = $account->fresh(['package', 'packageDuration', 'server']);

                if ($targetPackage !== null) {
                    $duration = $this->billingPackageService->resolveBillingDuration($account, $targetPackage);
                    $account->update([
                        'package_id' => $targetPackage->id,
                        'package_duration_id' => $duration->id,
                    ]);
                    $account = $account->fresh(['package', 'packageDuration', 'server']);
                } else {
                    $account = $this->billingPackageService->syncBillingPackage($account);
                }

                $this->assertBillingPackageOnServer($account, $toServer);

                $push = $this->remnawaveService->provisionUserFromDatabase($account);

                $account = $account->fresh();
                $subUrl = (string) ($account->remnawave_subscription_url ?? '');

                $this->activityLogService->log($actor, 'account.sanaei_to_remnawave', $account, [
                    'migration_id' => $migration->id,
                    'old_server_id' => $oldServerId,
                    'new_server_id' => $toServer->id,
                    'old_package_id' => $oldPackageId,
                    'new_package_id' => $account->package_id,
                    'push_action' => $push['action'] ?? null,
                ]);

                return ServerMigrationEntry::query()->create(array_merge($base, [
                    'status' => 'success',
                    'message' => ($push['message'] ?? 'انتقال انجام شد.')
                        .' | حجم پنل: '
                        .round($account->data_used_bytes / (1024 ** 3), 2)
                        .'/'
                        .($account->isUnlimited() ? '∞' : round((int) $account->data_limit_bytes / (1024 ** 3), 2))
                        .' GB',
                    'subscription_url' => $subUrl !== '' ? $subUrl : null,
                    'data_used_bytes' => $account->data_used_bytes,
                    'data_limit_bytes' => $account->data_limit_bytes,
                    'expiry_at' => $account->expiry_at,
                ]));
            });
        } catch (Throwable $exception) {
            report($exception);

            return ServerMigrationEntry::query()->create(array_merge($base, [
                'status' => 'failed',
                'message' => __('services.migration_failed'),
                'error' => $exception->getMessage(),
            ]));
        }
    }

    protected function tryDisableOnSourceServer(Account $account, Server $fromServer): void
    {
        if (! $fromServer->isSanaei()) {
            return;
        }

        try {
            $account->setRelation('server', $fromServer);
            $this->accountService->disableRemoteOnly($account);
        } catch (Throwable) {
            // اختیاری — انتقال فقط از دیتابیس است؛ خطای API ثنایی مانع انتقال نیست.
        }
    }

    protected function resolveTargetPackage(Server $toServer, ?int $targetPackageId): ?Package
    {
        if ($targetPackageId === null) {
            return null;
        }

        $package = Package::query()
            ->active()
            ->where('service_type', ServiceType::Remnawave)
            ->whereHas('servers', fn ($q) => $q->where('servers.id', $toServer->id))
            ->find($targetPackageId);

        if ($package === null) {
            throw new InvalidArgumentException(__('migrate.target_package_invalid'));
        }

        return $package;
    }

    protected function assertBillingPackageOnServer(Account $account, Server $toServer): void
    {
        $account->loadMissing('package.servers');
        $package = $account->package;

        if ($package === null) {
            throw new InvalidArgumentException(__('services.account_id_without_package', ['id' => $account->id]));
        }

        if ($package->service_type !== ServiceType::Remnawave) {
            throw new InvalidArgumentException(__('migrate.billing_package_service_mismatch'));
        }

        if (! $package->servers->contains('id', $toServer->id)) {
            throw new InvalidArgumentException(__('migrate.billing_package_server_mismatch', [
                'package' => $package->name,
                'server' => $toServer->name,
            ]));
        }
    }

    protected function describeDryRun(Account $account): string
    {
        $usedGb = round($account->data_used_bytes / (1024 ** 3), 2);
        $limitGb = $account->isUnlimited()
            ? '∞'
            : round((int) $account->data_limit_bytes / (1024 ** 3), 2);

        return "#{$account->id} «{$account->remote_username}» — {$usedGb}/{$limitGb} GB — انقضا "
            .($account->expiry_at?->format('Y-m-d H:i') ?? '—');
    }

    protected function assertPair(Server $from, Server $to): void
    {
        if (! $from->isSanaei()) {
            throw new InvalidArgumentException(__('services.migration_source_must_be_sanaei'));
        }

        if (! $to->isRemnawave()) {
            throw new InvalidArgumentException(__('services.migration_target_must_be_remnawave'));
        }

        if (! $from->is_active || ! $to->is_active) {
            throw new InvalidArgumentException(__('services.migration_both_must_be_active'));
        }

        if ($from->id === $to->id) {
            throw new InvalidArgumentException(__('services.migration_same_server'));
        }

        if (! $to->hasStoredRemnawaveApiToken()) {
            throw new InvalidArgumentException(__('services.migration_target_token_missing'));
        }
    }
}
