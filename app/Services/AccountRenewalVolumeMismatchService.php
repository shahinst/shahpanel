<?php

namespace App\Services;

use App\Enums\InvoiceType;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Detects elastic renewals where volume add was billed but purchased/limit were not increased.
 */
class AccountRenewalVolumeMismatchService
{
    private const USAGE_PRESERVED_TOLERANCE_BYTES = 50 * 1024 * 1024;

    public function __construct(
        protected AccountRenewalPricingService $renewalPricing,
        protected UserPackagePricingService $pricingService,
        protected AccountService $accountService,
    ) {}

    /**
     * @return Collection<int, array{
     *     account: Account,
     *     add_gb: float,
     *     purchased_gb: float,
     *     expected_purchased_gb: float,
     *     renewal_at: ?Carbon,
     *     detail: string
     * }>
     */
    public function findMismatchedAccounts(?int $withinDays = 90, ?int $accountId = null): Collection
    {
        $since = $accountId !== null
            ? null
            : now()->subDays(max(1, $withinDays));

        if ($accountId !== null) {
            $account = Account::query()
                ->whereNull('refunded_at')
                ->with(['package', 'packageDuration', 'server', 'ownerSeller'])
                ->find($accountId);

            if ($account === null) {
                return collect();
            }

            $row = $this->inspectAccount($account, $since);

            return $row !== null ? collect([$row]) : collect();
        }

        $invoiceSince = $since ?? now()->subDays(max(1, $withinDays));

        $accounts = Account::query()
            ->whereNull('refunded_at')
            ->whereHas('invoices', fn ($q) => $q
                ->where('type', InvoiceType::Renewal)
                ->where('issued_at', '>=', $invoiceSince))
            ->with(['package', 'packageDuration', 'server', 'ownerSeller'])
            ->get()
            ->filter(fn (Account $account): bool => (bool) $account->package?->isElastic());

        return $accounts
            ->map(fn (Account $account): ?array => $this->inspectAccount($account, $since))
            ->filter()
            ->values();
    }

    /**
     * Human-readable inspection notes when no mismatch is detected.
     */
    public function diagnoseAccount(int $accountId, ?int $withinDays = 90): string
    {
        $account = Account::query()
            ->with(['package', 'packageDuration', 'ownerSeller'])
            ->find($accountId);

        if ($account === null) {
            return 'اکانت پیدا نشد.';
        }

        if ($account->refunded_at !== null) {
            return 'اکانت refund شده است.';
        }

        if (! $account->package?->isElastic()) {
            return 'پکیج اکانت elastic نیست (pricing_model/min/max).';
        }

        $since = now()->subDays(max(1, $withinDays));
        $renewalLog = $this->findRenewalLog($account, null);
        $invoice = $this->findRenewalInvoice($account, null);
        $billedGb = $invoice !== null ? $this->inferBilledGb($account, $invoice) : null;
        $current = $this->resolvePurchasedDataGb($account);

        $lines = [
            sprintf('purchased=%s GB | limit=%s | used=%s',
                $this->formatGb($current),
                $account->data_limit_bytes !== null ? format_data_size((int) $account->data_limit_bytes) : '—',
                format_data_size((int) $account->data_used_bytes),
            ),
        ];

        if ($renewalLog === null) {
            $lines[] = 'لاگ account.renewed پیدا نشد.';
        } else {
            $payload = is_array($renewalLog->payload) ? $renewalLog->payload : [];
            $lines[] = sprintf(
                'آخرین لاگ: %s | mode=%s | renewal_gb=%s | purchased_before=%s',
                $renewalLog->created_at?->format('Y-m-d H:i') ?? '—',
                $payload['renewal_mode'] ?? '—',
                $payload['renewal_gb'] ?? '—',
                $payload['purchased_before_gb'] ?? '—',
            );
        }

        if ($invoice === null) {
            $lines[] = 'فاکتور Renewal پیدا نشد.';
        } else {
            $lines[] = sprintf(
                'آخرین فاکتور: %s | total=%s | billed_gb≈%s',
                $invoice->issued_at?->format('Y-m-d H:i') ?? '—',
                $invoice->total,
                $billedGb !== null ? $this->formatGb($billedGb) : '—',
            );
        }

        $lines[] = 'اگر هنوز مشکل دارید: php artisan accounts:repair-volume-topup --account='.$accountId.' --add-gb=10 --apply';

        return implode("\n", $lines);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function repair(Account $account, float $addGb): array
    {
        $addGb = round(max(0.01, $addGb), 2);

        try {
            $this->accountService->applyVolumeTopUp($account, $addGb, syncRemote: true);

            return [
                'ok' => true,
                'message' => sprintf(
                    '+%s GB اعمال شد — purchased=%s GB',
                    $this->formatGb($addGb),
                    $this->formatGb((float) $account->fresh()->purchased_data_gb),
                ),
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array{
     *     account: Account,
     *     add_gb: float,
     *     purchased_gb: float,
     *     expected_purchased_gb: float,
     *     renewal_at: ?Carbon,
     *     detail: string
     * }|null
     */
    protected function inspectAccount(Account $account, ?Carbon $since): ?array
    {
        if (! $account->package?->isElastic()) {
            return null;
        }

        $renewalLog = $this->findRenewalLog($account, $since);
        $invoice = $this->findRenewalInvoice($account, $since);
        $billedGb = $invoice !== null ? $this->inferBilledGb($account, $invoice) : null;

        if ($renewalLog !== null) {
            $fromLog = $this->inspectFromActivityLog($account, $renewalLog, $billedGb);

            if ($fromLog !== null) {
                return $fromLog;
            }
        }

        if ($invoice !== null && $billedGb !== null && $billedGb > 0) {
            return $this->inspectFromInvoice($account, $invoice, $billedGb, $renewalLog);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function inspectFromActivityLog(Account $account, ActivityLog $renewalLog, ?float $billedGb): ?array
    {
        $payload = is_array($renewalLog->payload) ? $renewalLog->payload : [];
        $mode = (string) ($payload['renewal_mode'] ?? 'same');
        $logGb = isset($payload['renewal_gb']) ? round((float) $payload['renewal_gb'], 2) : null;
        $currentPurchased = $this->resolvePurchasedDataGb($account);

        if ($mode === 'add_volume' && $logGb !== null && $logGb > 0) {
            return $this->buildMismatchRow(
                $account,
                $currentPurchased,
                $logGb,
                isset($payload['purchased_before_gb']) ? round((float) $payload['purchased_before_gb'], 2) : null,
                $renewalLog->created_at,
                sprintf('لاگ add_volume +%s GB', $this->formatGb($logGb)),
            );
        }

        if ($billedGb !== null && $logGb !== null && $billedGb > $logGb + 0.01) {
            $beforeGb = isset($payload['purchased_before_gb'])
                ? round((float) $payload['purchased_before_gb'], 2)
                : $logGb;
            $usedPreserved = (int) $account->data_used_bytes > self::USAGE_PRESERVED_TOLERANCE_BYTES;

            if ($mode === 'same' || $mode === 'add_volume') {
                $expectedPurchased = $usedPreserved
                    ? round($beforeGb + $billedGb, 2)
                    : round(max($billedGb, $beforeGb + $billedGb), 2);

                $missingGb = round($expectedPurchased - $currentPurchased, 2);

                if ($missingGb > 0.01) {
                    return [
                        'account' => $account,
                        'add_gb' => $missingGb,
                        'purchased_gb' => $currentPurchased,
                        'expected_purchased_gb' => $expectedPurchased,
                        'renewal_at' => $renewalLog->created_at,
                        'detail' => sprintf(
                            'فاکتور %s GB — لاگ mode=%s / %s GB → باید %s GB',
                            $this->formatGb($billedGb),
                            $mode,
                            $this->formatGb($logGb),
                            $this->formatGb($expectedPurchased),
                        ),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function inspectFromInvoice(
        Account $account,
        Invoice $invoice,
        float $billedGb,
        ?ActivityLog $renewalLog,
    ): ?array {
        $currentPurchased = $this->resolvePurchasedDataGb($account);
        $sameGb = $this->renewalPricing->billableDataGb($account);
        $usedPreserved = (int) $account->data_used_bytes > self::USAGE_PRESERVED_TOLERANCE_BYTES;

        if ($sameGb !== null && abs($billedGb - $sameGb) < 0.01) {
            return null;
        }

        if ($billedGb <= $currentPurchased + 0.01) {
            return null;
        }

        if ($usedPreserved && $sameGb !== null) {
            $expectedPurchased = round($sameGb + $billedGb, 2);

            return $this->buildMismatchRow(
                $account,
                $currentPurchased,
                $billedGb,
                $sameGb,
                $invoice->issued_at,
                sprintf(
                    'فاکتور %s GB + حجم فعلی %s GB (مصرف حفظ‌شده)',
                    $this->formatGb($billedGb),
                    $this->formatGb($sameGb),
                ),
                $expectedPurchased,
            );
        }

        if ($billedGb > $currentPurchased + 0.01) {
            $expectedPurchased = round($billedGb, 2);
            $missingGb = round($expectedPurchased - $currentPurchased, 2);

            if ($missingGb > 0.01) {
                return [
                    'account' => $account,
                    'add_gb' => $missingGb,
                    'purchased_gb' => $currentPurchased,
                    'expected_purchased_gb' => $expectedPurchased,
                    'renewal_at' => $invoice->issued_at,
                    'detail' => sprintf(
                        'فاکتور %s GB — purchased %s → باید %s GB',
                        $this->formatGb($billedGb),
                        $this->formatGb($currentPurchased),
                        $this->formatGb($expectedPurchased),
                    ),
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildMismatchRow(
        Account $account,
        float $currentPurchased,
        float $addGb,
        ?float $beforeGb,
        ?Carbon $renewalAt,
        string $reason,
        ?float $expectedPurchasedOverride = null,
    ): ?array {
        $beforeGb ??= $this->inferPurchasedBeforeGb($currentPurchased, $addGb);
        $expectedPurchased = $expectedPurchasedOverride ?? round($beforeGb + $addGb, 2);

        if ($currentPurchased >= ($expectedPurchased - 0.01)) {
            return null;
        }

        $missingGb = round($expectedPurchased - $currentPurchased, 2);

        if ($missingGb <= 0.01) {
            return null;
        }

        return [
            'account' => $account,
            'add_gb' => $missingGb,
            'purchased_gb' => $currentPurchased,
            'expected_purchased_gb' => $expectedPurchased,
            'renewal_at' => $renewalAt,
            'detail' => sprintf(
                '%s — purchased %s → باید %s GB',
                $reason,
                $this->formatGb($currentPurchased),
                $this->formatGb($expectedPurchased),
            ),
        ];
    }

    protected function findRenewalLog(Account $account, ?Carbon $since): ?ActivityLog
    {
        $query = ActivityLog::query()
            ->where('action', 'account.renewed')
            ->where('entity_id', $account->id);

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        return $query->orderByDesc('created_at')->first();
    }

    protected function findRenewalInvoice(Account $account, ?Carbon $since): ?Invoice
    {
        $query = Invoice::query()
            ->where('account_id', $account->id)
            ->where('type', InvoiceType::Renewal);

        if ($since !== null) {
            $query->where('issued_at', '>=', $since);
        }

        return $query->orderByDesc('issued_at')->first();
    }

    protected function inferBilledGb(Account $account, Invoice $invoice): ?float
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

            $total = (float) $invoice->total;
            $inferred = round($total / $unit, 2);

            return $inferred > 0 ? $inferred : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function inferPurchasedBeforeGb(float $currentPurchased, float $addGb): float
    {
        if ($currentPurchased > $addGb) {
            return round($currentPurchased - $addGb, 2);
        }

        return $currentPurchased;
    }

    protected function resolvePurchasedDataGb(Account $account): float
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
