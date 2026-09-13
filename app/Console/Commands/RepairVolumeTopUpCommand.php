<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\AccountBillingUsageSummaryService;
use App\Services\AccountPurchasedVolumeReconstructionService;
use App\Services\AccountRenewalVolumeMismatchService;
use App\Services\AccountService;
use Illuminate\Console\Command;
use Throwable;

class RepairVolumeTopUpCommand extends Command
{
    protected $signature = 'accounts:repair-volume-topup
                            {--days=90 : بازه بررسی تمدیدها (روز)}
                            {--apply : اعمال اصلاح روی دیتابیس و پنل}
                            {--account= : فقط یک اکانت (ID)}
                            {--add-gb= : گیگ اضافه دستی (فقط با --account)}
                            {--subtract-gb= : گیگ کم کردن دستی (فقط با --account)}
                            {--set-gb= : تنظیم purchased/limit روی مقدار مشخص (GB)}';

    protected $description = 'اصلاح حجم اکانت‌های elastic (تمدید اشتباه، شارژ دستی، تنظیم سقف)';

    public function handle(
        AccountRenewalVolumeMismatchService $mismatchService,
        AccountPurchasedVolumeReconstructionService $volumeReconstruction,
        AccountBillingUsageSummaryService $billingSummary,
        AccountService $accountService,
    ): int {
        $apply = (bool) $this->option('apply');
        $days = max(1, (int) $this->option('days'));
        $accountId = $this->option('account');
        $accountId = $accountId !== null && $accountId !== '' ? (int) $accountId : null;

        $manualResult = $this->handleManualVolumeChange($accountService, $accountId, $apply);

        if ($manualResult !== null) {
            return $manualResult;
        }

        $mismatches = $mismatchService->findMismatchedAccounts($days, $accountId);

        if ($mismatches->isEmpty()) {
            if ($accountId !== null) {
                $account = Account::query()->find($accountId);

                if ($account !== null) {
                    $this->printBillingLedger($billingSummary, $account);

                    $expected = $volumeReconstruction->reconstructExpectedPurchasedGb($account);
                    $current = $volumeReconstruction->currentPurchasedGb($account);

                    if ($expected !== null && $expected > $current + 0.01) {
                        $this->warn(sprintf(
                            'سوابق خرید/تمدید: باید %s GB — فعلاً %s GB (کسری %s GB)',
                            rtrim(rtrim(number_format($expected, 2, '.', ''), '0'), '.'),
                            rtrim(rtrim(number_format($current, 2, '.', ''), '0'), '.'),
                            rtrim(rtrim(number_format($expected - $current, 2, '.', ''), '0'), '.'),
                        ));

                        if ($apply) {
                            $result = $volumeReconstruction->repairIfUnderRecorded($account, syncRemote: true);

                            if ($result['repaired']) {
                                $this->info('✓ '.$result['message']);
                            } elseif ($result['message']) {
                                $this->error($result['message']);
                            }
                        } else {
                            $this->comment('برای اعمال: php artisan accounts:repair-volume-topup --account='.$accountId.' --apply');
                        }

                        return self::SUCCESS;
                    }
                }

                $this->newLine();
                $this->line($mismatchService->diagnoseAccount($accountId, $days));
            } else {
                $this->info('اکانت ناسازگار پیدا نشد.');
            }

            return self::SUCCESS;
        }

        $fixed = 0;
        $failed = 0;

        foreach ($mismatches as $row) {
            /** @var Account $account */
            $account = $row['account'];
            $addGb = (float) $row['add_gb'];

            $line = sprintf(
                '#%d %s | purchased=%s GB → +%s GB = %s GB | %s | %s',
                $account->id,
                $account->remote_username,
                rtrim(rtrim(number_format((float) $row['purchased_gb'], 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($addGb, 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format((float) $row['expected_purchased_gb'], 2, '.', ''), '0'), '.'),
                $row['renewal_at']?->format('Y-m-d H:i') ?? '—',
                $row['detail'],
            );

            if (! $apply) {
                $this->warn($line.' (dry-run)');

                continue;
            }

            $result = $mismatchService->repair($account, $addGb);

            if ($result['ok']) {
                $fixed++;
                $this->info($line.' ✓');
            } else {
                $failed++;
                $this->error($line.' — '.$result['message']);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'نامزد: %d | اصلاح‌شده: %d | خطا: %d%s',
            $mismatches->count(),
            $fixed,
            $failed,
            $apply ? '' : ' (dry-run)',
        ));

        if ($mismatches->isNotEmpty() && ! $apply) {
            $this->comment('برای اعمال: php artisan accounts:repair-volume-topup --apply');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function handleManualVolumeChange(
        AccountService $accountService,
        ?int $accountId,
        bool $apply,
    ): ?int {
        $setGb = $this->option('set-gb');
        $subtractGb = $this->option('subtract-gb');
        $addGb = $this->option('add-gb');

        $hasSet = $setGb !== null && $setGb !== '';
        $hasSubtract = $subtractGb !== null && $subtractGb !== '';
        $hasAdd = $addGb !== null && $addGb !== '';

        if (! $hasSet && ! $hasSubtract && ! $hasAdd) {
            return null;
        }

        if ($accountId === null) {
            $this->error('برای --add-gb / --subtract-gb / --set-gb باید --account را هم بدهید.');

            return self::FAILURE;
        }

        if (($hasSet && $hasSubtract) || ($hasSet && $hasAdd) || ($hasSubtract && $hasAdd)) {
            $this->error('فقط یکی از --set-gb، --subtract-gb یا --add-gb را بدهید.');

            return self::FAILURE;
        }

        $account = Account::query()->find($accountId);

        if ($account === null) {
            $this->error('اکانت پیدا نشد.');

            return self::FAILURE;
        }

        $currentGb = $this->resolvePurchasedGb($account);

        if ($hasSet) {
            $targetGb = round((float) $setGb, 2);
            $line = sprintf(
                '#%d %s | %s GB → %s GB (تنظیم)',
                $account->id,
                $account->remote_username,
                $this->formatGb($currentGb),
                $this->formatGb($targetGb),
            );
        } elseif ($hasSubtract) {
            $subGb = round((float) $subtractGb, 2);
            $targetGb = round(max(0.01, $currentGb - $subGb), 2);
            $line = sprintf(
                '#%d %s | %s GB − %s GB → %s GB',
                $account->id,
                $account->remote_username,
                $this->formatGb($currentGb),
                $this->formatGb($subGb),
                $this->formatGb($targetGb),
            );
        } else {
            $subGb = round((float) $addGb, 2);

            if ($subGb <= 0) {
                $this->error('مقدار --add-gb نامعتبر است.');

                return self::FAILURE;
            }

            $targetGb = round($currentGb + $subGb, 2);
            $line = sprintf(
                '#%d %s | %s GB + %s GB → %s GB',
                $account->id,
                $account->remote_username,
                $this->formatGb($currentGb),
                $this->formatGb($subGb),
                $this->formatGb($targetGb),
            );
        }

        if ($targetGb <= 0) {
            $this->error('مقدار نهایی حجم نامعتبر است.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn($line.' (dry-run)');
            $this->comment('برای اعمال: php artisan accounts:repair-volume-topup --account='.$accountId.' --set-gb='.$this->formatGb($targetGb).' --apply');

            return self::SUCCESS;
        }

        try {
            if ($hasAdd) {
                $accountService->applyVolumeTopUp($account, round((float) $addGb, 2), syncRemote: true);
            } else {
                $accountService->setPurchasedVolumeGb($account, $targetGb, syncRemote: true);
            }

            $account->refresh();
            $this->info($line.' ✓ — purchased='.$this->formatGb((float) $account->purchased_data_gb).' GB');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($line.' — '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    protected function resolvePurchasedGb(Account $account): float
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

    protected function printBillingLedger(AccountBillingUsageSummaryService $billingSummary, Account $account): void
    {
        $summary = $billingSummary->summarize($account);

        $this->newLine();
        $this->info('=== سوابق حجم (خرید/تمدید) ===');

        foreach ($summary['entries'] as $entry) {
            $delta = (float) $entry['delta_gb'];
            $deltaLabel = $delta > 0 ? sprintf('+%s GB', $this->formatGb($delta)) : '—';
            $this->line(sprintf(
                '  %s | %s | جمع: %s GB',
                $entry['label'],
                $deltaLabel,
                $this->formatGb((float) $entry['gb']),
            ));
        }

        $this->line(sprintf(
            '  کل سقف خریداری‌شده: %s GB (واحد: گیگابایت — از renewal_gb و فاکتور)',
            $this->formatGb($summary['total_purchased_gb']),
        ));
        $this->line(sprintf(
            '  مصرف دوره جاری: %s | باقیمانده: %s',
            format_data_size((int) $summary['period_used_bytes']),
            format_data_size((int) $summary['remaining_bytes']),
        ));
        $this->line(sprintf(
            '  جمع مصرف ثبت‌شده (کل دوره‌ها): %s — فقط تاریخچه؛ برای باقیمانده استفاده نمی‌شود',
            format_data_size((int) $summary['lifetime_logged_bytes']),
        ));
        $this->newLine();
    }
}
