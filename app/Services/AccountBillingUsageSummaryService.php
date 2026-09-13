<?php

namespace App\Services;

use App\Enums\InvoiceType;
use App\Models\Account;
use App\Models\AccountUsageLog;
use App\Models\ActivityLog;
use App\Models\Invoice;
use Throwable;

/**
 * Derives purchased volume from billing history (invoices + renewal logs),
 * usage from account_usage_logs, and remaining = purchased − logged usage.
 */
class AccountBillingUsageSummaryService
{
    public function __construct(
        protected UserPackagePricingService $pricingService,
        protected AccountService $accountService,
    ) {}

    /**
     * @return array{
     *     entries: list<array{kind: string, label: string, gb: float, delta_gb: float, mode: ?string, at: ?string}>,
     *     total_purchased_gb: float,
     *     total_purchased_bytes: int,
     *     period_used_bytes: int,
     *     lifetime_logged_bytes: int,
     *     remaining_bytes: int,
     *     usage_percent: ?float
     * }
     */
    public function summarize(Account $account): array
    {
        $account->loadMissing(['package', 'packageDuration', 'ownerSeller']);

        $entries = $this->buildVolumeEntries($account);
        $totalGb = $this->totalPurchasedGbFromEntries($entries, $account);
        $totalBytes = (int) round($totalGb * 1024 * 1024 * 1024);
        $periodUsed = $this->currentPeriodUsedBytes($account);
        $lifetimeLogged = $this->lifetimeLoggedUsageBytes($account);
        $remainingBytes = max(0, $totalBytes - $periodUsed);
        $usagePercent = $totalBytes > 0
            ? min(100, round(($periodUsed / $totalBytes) * 100, 1))
            : null;

        return [
            'entries' => $entries,
            'total_purchased_gb' => $totalGb,
            'total_purchased_bytes' => $totalBytes,
            'period_used_bytes' => $periodUsed,
            'lifetime_logged_bytes' => $lifetimeLogged,
            'remaining_bytes' => $remainingBytes,
            'usage_percent' => $usagePercent,
        ];
    }

    /**
     * @return array{repaired: bool, message: ?string, summary: array<string, mixed>}
     */
    public function repairPurchasedVolumeIfNeeded(Account $account, bool $syncRemote = true): array
    {
        $summary = $this->summarize($account);
        $expectedGb = $summary['total_purchased_gb'];
        $currentGb = $this->currentPurchasedGb($account);

        if ($expectedGb <= 0 || $expectedGb <= $currentGb + 0.01) {
            return [
                'repaired' => false,
                'message' => null,
                'summary' => $summary,
            ];
        }

        try {
            $this->accountService->setPurchasedVolumeGb($account, $expectedGb, syncRemote: $syncRemote);

            return [
                'repaired' => true,
                'message' => sprintf(
                    'حجم از %s GB به %s GB اصلاح شد (بر اساس سوابق خرید/تمدید).',
                    $this->formatGb($currentGb),
                    $this->formatGb($expectedGb),
                ),
                'summary' => $this->summarize($account->fresh() ?? $account),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'repaired' => false,
                'message' => $exception->getMessage(),
                'summary' => $summary,
            ];
        }
    }

    public function lifetimeLoggedUsageBytes(Account $account): int
    {
        return (int) AccountUsageLog::query()
            ->where('account_id', $account->id)
            ->get()
            ->sum(fn (AccountUsageLog $log): int => max(0, (int) $log->rx_delta_bytes) + max(0, (int) $log->tx_delta_bytes));
    }

    public function currentPeriodUsedBytes(Account $account): int
    {
        return max(0, $this->accountService->resolveAuthoritativeUsedBytesForDisplay($account));
    }

    /**
     * @return list<array{kind: string, label: string, gb: float, delta_gb: float, mode: ?string, at: ?string}>
     */
    protected function buildVolumeEntries(Account $account): array
    {
        $entries = [];
        $runningGb = 0.0;

        $initialGb = $this->inferInitialPurchaseGb($account);
        if ($initialGb !== null && $initialGb > 0) {
            $runningGb = $initialGb;
            $entries[] = [
                'kind' => 'purchase',
                'label' => __('accounts.billing_volume_purchase'),
                'gb' => $runningGb,
                'delta_gb' => $initialGb,
                'mode' => null,
                'at' => $this->initialPurchaseAt($account),
            ];
        }

        $renewalLogs = ActivityLog::query()
            ->where('entity_id', $account->id)
            ->where('action', 'account.renewed')
            ->orderBy('id')
            ->get();

        $sequence = 0;

        foreach ($renewalLogs as $log) {
            $sequence++;
            $payload = is_array($log->payload) ? $log->payload : [];
            $mode = (string) ($payload['renewal_mode'] ?? 'same');
            $renewalGb = isset($payload['renewal_gb']) ? round((float) $payload['renewal_gb'], 2) : null;
            $deltaGb = 0.0;

            if ($mode === 'add_volume' && $renewalGb !== null && $renewalGb > 0) {
                $deltaGb = $renewalGb;
                $runningGb = round($runningGb + $renewalGb, 2);
            } elseif ($mode === 'upgrade_volume' && $renewalGb !== null && $renewalGb > 0) {
                $deltaGb = round($renewalGb - $runningGb, 2);
                $runningGb = $renewalGb;
            }

            $entries[] = [
                'kind' => 'renewal',
                'label' => __('accounts.billing_volume_renewal', ['number' => persian_digits((string) $sequence)]),
                'gb' => $runningGb,
                'delta_gb' => $deltaGb,
                'mode' => $mode,
                'at' => $log->created_at?->toIso8601String(),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array{kind: string, gb: float, delta_gb: float}>  $entries
     */
    protected function totalPurchasedGbFromEntries(array $entries, Account $account): float
    {
        if ($entries !== []) {
            $last = $entries[array_key_last($entries)];

            return max(0, round((float) $last['gb'], 2));
        }

        return $this->currentPurchasedGb($account);
    }

    protected function inferInitialPurchaseGb(Account $account): ?float
    {
        $firstRenewal = ActivityLog::query()
            ->where('entity_id', $account->id)
            ->where('action', 'account.renewed')
            ->orderBy('id')
            ->first();

        if ($firstRenewal !== null) {
            $payload = is_array($firstRenewal->payload) ? $firstRenewal->payload : [];
            if (isset($payload['purchased_before_gb'])) {
                $before = round((float) $payload['purchased_before_gb'], 2);

                if ($before > 0) {
                    return $before;
                }
            }
        }

        if ($account->purchased_data_gb !== null && (float) $account->purchased_data_gb > 0) {
            $renewalCount = ActivityLog::query()
                ->where('entity_id', $account->id)
                ->where('action', 'account.renewed')
                ->count();

            if ($renewalCount === 0) {
                return round((float) $account->purchased_data_gb, 2);
            }
        }

        $invoice = Invoice::query()
            ->where('account_id', $account->id)
            ->where('type', InvoiceType::NewAccount)
            ->orderBy('issued_at')
            ->orderBy('id')
            ->first();

        if ($invoice !== null) {
            $fromInvoice = $this->inferGbFromInvoice($account, $invoice);
            if ($fromInvoice !== null && $fromInvoice > 0) {
                return $fromInvoice;
            }
        }

        $current = $this->currentPurchasedGb($account);

        return $current > 0 ? $current : null;
    }

    protected function initialPurchaseAt(Account $account): ?string
    {
        $issuedAt = Invoice::query()
            ->where('account_id', $account->id)
            ->where('type', InvoiceType::NewAccount)
            ->orderBy('issued_at')
            ->value('issued_at');

        return $issuedAt?->toIso8601String() ?? $account->created_at?->toIso8601String();
    }

    protected function inferGbFromInvoice(Account $account, Invoice $invoice): ?float
    {
        $duration = $account->packageDuration;
        $buyer = $account->ownerSeller;

        if ($duration === null || $buyer === null) {
            return null;
        }

        try {
            $unit = (float) $this->pricingService->requireWholesalePrice($buyer, $duration);

            if ($unit <= 0) {
                return null;
            }

            $inferred = round((float) $invoice->total / $unit, 2);

            return $inferred > 0 ? $inferred : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function currentPurchasedGb(Account $account): float
    {
        if ($account->purchased_data_gb !== null && (float) $account->purchased_data_gb > 0) {
            return round((float) $account->purchased_data_gb, 2);
        }

        if ($account->data_limit_bytes !== null && (int) $account->data_limit_bytes > 0) {
            return round((int) $account->data_limit_bytes / (1024 ** 3), 2);
        }

        return 0.0;
    }

    protected function formatGb(float $gb): string
    {
        return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.');
    }
}
