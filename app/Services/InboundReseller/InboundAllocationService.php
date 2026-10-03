<?php

namespace App\Services\InboundReseller;

use App\Enums\AccountStatus;
use App\Enums\NotificationType;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountUsageLog;
use App\Models\InboundAllocation;
use App\Models\Package;
use App\Models\User;
use App\Services\AccountService;
use App\Services\PanelAlertService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Runs the inbound reseller model: an agent sells on inbounds the admin gave
 * them, and pays for the traffic their accounts use instead of paying for
 * each account. This service meters that traffic, bills it, and switches the
 * allocation's accounts off when the quota or the agent's credit runs out.
 */
class InboundAllocationService
{
    public function __construct(
        protected WalletService $wallets,
        protected PanelAlertService $alerts,
    ) {}

    /**
     * Refuse to build or renew an account on an allocation that cannot carry
     * more traffic.
     */
    public function assertCanProvision(?Package $package): void
    {
        if ($package === null || ! $package->isAgentOwned()) {
            return;
        }

        $allocation = $package->inboundAllocation;

        if ($allocation === null) {
            throw new InvalidArgumentException(__('inbound_resellers.allocation_missing'));
        }

        if (! $allocation->isActive()) {
            throw new InvalidArgumentException(__('inbound_resellers.allocation_suspended'));
        }

        if ($allocation->quotaReached()) {
            throw new InvalidArgumentException(__('inbound_resellers.quota_reached'));
        }
    }

    /**
     * Meter and bill every active allocation. Run from the scheduler.
     *
     * @return array{allocations: int, billed: string}
     */
    public function billAll(): array
    {
        $count = 0;
        $billed = '0.00';

        InboundAllocation::query()->orderBy('id')->each(function (InboundAllocation $allocation) use (&$count, &$billed): void {
            try {
                $amount = $this->bill($allocation);
                $billed = bcadd($billed, $amount, 2);
                $count++;
            } catch (Throwable $exception) {
                Log::error('Inbound allocation billing failed', [
                    'allocation_id' => $allocation->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });

        return ['allocations' => $count, 'billed' => $billed];
    }

    /**
     * Add the traffic logged since the last run, charge whole gigabytes to the
     * agent and credit the admin, then suspend if the quota or the credit is
     * gone. Returns the amount charged.
     */
    public function bill(InboundAllocation $allocation): string
    {
        $charged = DB::transaction(function () use ($allocation): string {
            $allocation = InboundAllocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();

            $accountIds = Account::withTrashed()->where('inbound_allocation_id', $allocation->id)->pluck('id');
            $charged = '0.00';

            if ($accountIds->isNotEmpty()) {
                $usage = AccountUsageLog::query()
                    ->whereIn('account_id', $accountIds->all())
                    ->where('id', '>', $allocation->usage_watermark)
                    ->selectRaw('MAX(id) as last_id, COALESCE(SUM(rx_delta_bytes + tx_delta_bytes), 0) as bytes')
                    ->first();

                if ($usage !== null && $usage->last_id !== null) {
                    $allocation->usage_watermark = (int) $usage->last_id;
                    $allocation->used_bytes = (int) $allocation->used_bytes + (int) $usage->bytes;
                }
            }

            // Whole gigabytes only; the rest waits for the next run.
            $unbilled = max(0, (int) $allocation->used_bytes - (int) $allocation->billed_bytes);
            $gigabytes = intdiv($unbilled, InboundAllocation::GB);

            if ($gigabytes > 0 && bccomp((string) $allocation->price_per_gb, '0', 2) > 0) {
                $charged = bcmul((string) $gigabytes, (string) $allocation->price_per_gb, 2);
                $context = [
                    'currency' => $allocation->moneyCurrency()->value,
                    'source_user_id' => $allocation->agent_user_id,
                    'description' => __('inbound_resellers.charge_description', [
                        'gb' => $gigabytes,
                        'allocation' => $allocation->label(),
                    ]),
                ];

                $agent = User::query()->findOrFail($allocation->agent_user_id);
                $this->wallets->debit($agent, $charged, TransactionType::InboundUsage, $context, allowNegative: true);

                $admin = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();
                if ($admin !== null) {
                    $this->wallets->credit($admin, $charged, TransactionType::Revenue, $context);
                }

                $allocation->billed_amount = bcadd((string) $allocation->billed_amount, $charged, 2);
            }

            if ($gigabytes > 0) {
                $allocation->billed_bytes = (int) $allocation->billed_bytes + $gigabytes * InboundAllocation::GB;
            }

            $allocation->last_billed_at = now();
            $allocation->save();

            return $charged;
        });

        $this->enforceLimits($allocation->fresh());

        return $charged;
    }

    /**
     * Suspend an active allocation whose quota is used up or whose agent ran
     * past their credit.
     */
    public function enforceLimits(InboundAllocation $allocation): void
    {
        $balance = (string) $this->wallets->getOrCreateWallet($allocation->agent, $allocation->moneyCurrency())->balance;
        $floor = bcmul((string) $allocation->credit_limit, '-1', 2);
        $overdrawn = bccomp($balance, $floor, 2) < 0;

        // Suspended for money and the agent has since topped up: back on by
        // itself, without waiting for the admin.
        if (! $allocation->isActive()) {
            if ($allocation->suspended_reason === InboundAllocation::SUSPENDED_BALANCE && ! $overdrawn && ! $allocation->quotaReached()) {
                $this->resume($allocation);
            }

            return;
        }

        if ($allocation->quotaReached()) {
            $this->suspend($allocation, InboundAllocation::SUSPENDED_QUOTA);

            return;
        }

        if ($overdrawn) {
            $this->suspend($allocation, InboundAllocation::SUSPENDED_BALANCE);
        }
    }

    /**
     * Switch every active account of the allocation off and remember which,
     * so resuming turns exactly those back on.
     */
    public function suspend(InboundAllocation $allocation, string $reason): void
    {
        $disabled = [];
        $accounts = app(AccountService::class);

        Account::query()
            ->where('inbound_allocation_id', $allocation->id)
            ->where('status', AccountStatus::Active)
            ->each(function (Account $account) use ($accounts, &$disabled): void {
                try {
                    $accounts->disableAccount($account);
                    $disabled[] = (int) $account->id;
                } catch (Throwable $exception) {
                    Log::warning('Could not disable account of suspended allocation', [
                        'account_id' => $account->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        $allocation->update([
            'status' => InboundAllocation::STATUS_SUSPENDED,
            'suspended_reason' => $reason,
            'suspended_account_ids' => array_values(array_unique(array_merge((array) $allocation->suspended_account_ids, $disabled))),
        ]);

        $agent = $allocation->agent;
        if ($agent !== null) {
            $this->alerts->notifyOnce(
                $agent,
                NotificationType::Warning,
                trans_for($agent, 'inbound_resellers.suspended_title'),
                trans_for($agent, 'inbound_resellers.suspended_body_'.$reason, ['allocation' => $allocation->label()]),
                'inbound-suspended:'.$allocation->id.':'.$reason,
                \Illuminate\Support\Facades\Route::has('agent.inbounds.index') ? route('agent.inbounds.index') : null,
            );
        }
    }

    /**
     * Back to active, and the accounts the suspension switched off back on —
     * unless they expired or ran out of data meanwhile.
     */
    public function resume(InboundAllocation $allocation): int
    {
        if ($allocation->quotaReached()) {
            throw new InvalidArgumentException(__('inbound_resellers.resume_quota_first'));
        }

        $accounts = app(AccountService::class);
        $enabled = 0;

        Account::query()
            ->whereIn('id', (array) $allocation->suspended_account_ids)
            ->where('status', AccountStatus::Disabled)
            ->each(function (Account $account) use ($accounts, &$enabled): void {
                if ($account->isExpired() || $account->isQuotaExhausted()) {
                    return;
                }

                try {
                    $accounts->enableAccount($account);
                    $enabled++;
                } catch (Throwable $exception) {
                    Log::warning('Could not re-enable account of resumed allocation', [
                        'account_id' => $account->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        $allocation->update([
            'status' => InboundAllocation::STATUS_ACTIVE,
            'suspended_reason' => null,
            'suspended_account_ids' => null,
        ]);

        return $enabled;
    }

    /**
     * Figures for the agent's and admin's pages.
     *
     * @return array{accounts: int, active_accounts: int, unbilled_bytes: int, packages: int}
     */
    public function summary(InboundAllocation $allocation): array
    {
        return [
            'accounts' => Account::query()->where('inbound_allocation_id', $allocation->id)->count(),
            'active_accounts' => Account::query()->where('inbound_allocation_id', $allocation->id)->where('status', AccountStatus::Active)->count(),
            'unbilled_bytes' => max(0, (int) $allocation->used_bytes - (int) $allocation->billed_bytes),
            'packages' => Package::query()->where('inbound_allocation_id', $allocation->id)->count(),
        ];
    }
}
