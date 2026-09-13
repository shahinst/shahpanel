<?php

namespace App\Services;

use App\Models\Account;
use Throwable;

/**
 * Rebuilds elastic purchased volume from billing activity (add_volume renewals, initial purchase).
 */
class AccountPurchasedVolumeReconstructionService
{
    public function __construct(
        protected AccountService $accountService,
    ) {}

    /**
     * Expected purchased GB from initial invoice + chronological renewal logs.
     */
    public function reconstructExpectedPurchasedGb(Account $account): ?float
    {
        $account->loadMissing(['package']);

        if (! $account->package?->isElastic()) {
            return null;
        }

        $summary = app(AccountBillingUsageSummaryService::class)->summarize($account);
        $totalGb = $summary['total_purchased_gb'];

        return $totalGb > 0 ? $totalGb : null;
    }

    public function currentPurchasedGb(Account $account): float
    {
        if ($account->purchased_data_gb !== null && (float) $account->purchased_data_gb > 0) {
            return round((float) $account->purchased_data_gb, 2);
        }

        if ($account->data_limit_bytes !== null && (int) $account->data_limit_bytes > 0) {
            return round((int) $account->data_limit_bytes / (1024 ** 3), 2);
        }

        return 0.0;
    }

    /**
     * @return array{expected_gb: ?float, current_gb: float, missing_gb: float, repaired: bool, message: ?string}
     */
    public function repairIfUnderRecorded(Account $account, bool $syncRemote = true): array
    {
        $expectedGb = $this->reconstructExpectedPurchasedGb($account);
        $currentGb = $this->currentPurchasedGb($account);

        if ($expectedGb === null || $expectedGb <= 0) {
            return [
                'expected_gb' => null,
                'current_gb' => $currentGb,
                'missing_gb' => 0.0,
                'repaired' => false,
                'message' => null,
            ];
        }

        $missingGb = round(max(0, $expectedGb - $currentGb), 2);

        if ($missingGb <= 0.01) {
            return [
                'expected_gb' => $expectedGb,
                'current_gb' => $currentGb,
                'missing_gb' => 0.0,
                'repaired' => false,
                'message' => null,
            ];
        }

        try {
            $this->accountService->setPurchasedVolumeGb($account, $expectedGb, syncRemote: $syncRemote);

            return [
                'expected_gb' => $expectedGb,
                'current_gb' => $currentGb,
                'missing_gb' => $missingGb,
                'repaired' => true,
                'message' => sprintf(
                    'حجم از %s GB به %s GB اصلاح شد (+%s GB از سوابق خرید/تمدید).',
                    $this->formatGb($currentGb),
                    $this->formatGb($expectedGb),
                    $this->formatGb($missingGb),
                ),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'expected_gb' => $expectedGb,
                'current_gb' => $currentGb,
                'missing_gb' => $missingGb,
                'repaired' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    protected function formatGb(float $gb): string
    {
        return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.');
    }
}
