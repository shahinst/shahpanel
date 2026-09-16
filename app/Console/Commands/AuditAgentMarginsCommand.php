<?php

namespace App\Console\Commands;

use App\Enums\MoneyCurrency;
use App\Services\AgentMarginAuditService;
use App\Services\AgentMarginCorrectionNoticeService;
use Illuminate\Console\Command;

class AuditAgentMarginsCommand extends Command
{
    protected $signature = 'accounting:audit-agent-margins
                            {--apply : اعمال کسر از کیف پول نماینده}
                            {--agent= : محدود به یک نماینده (شناسه کاربر)}
                            {--days=7 : مدت نمایش منوی اطلاع‌رسانی در پنل نماینده (روز)}';

    protected $description = 'بررسی پورسانت اضافه پرداخت‌شده به نمایندگان و بازگشت مازاد به کیف پول';

    public function handle(
        AgentMarginAuditService $auditService,
        AgentMarginCorrectionNoticeService $noticeService,
    ): int {
        $apply = (bool) $this->option('apply');
        $agentId = $this->option('agent');
        $agentFilter = $agentId !== null && $agentId !== '' ? (int) $agentId : null;

        $rows = $auditService->findOverpaidMargins($agentFilter);

        if ($rows->isEmpty()) {
            $this->info('پورسانت اضافه‌ای برای بازگشت پیدا نشد.');

            return self::SUCCESS;
        }

        $this->line($apply
            ? 'حالت اعمال: مازاد از کیف پول نمایندگان کسر می‌شود.'
            : 'حالت گزارش (dry-run): فقط نمایش. برای اعمال از --apply استفاده کنید.');
        $this->newLine();

        $tableRows = [];
        // جمع مازاد به تفکیک ارز نگه داشته می‌شود؛ جمع کردن مبالغ ارزهای مختلف معنا ندارد.
        $grandTotals = [];
        $applied = 0;

        foreach ($rows as $row) {
            $agent = $row['agent'];
            $account = $row['account'];
            $amount = $row['clawback_amount'];
            $rowCurrency = MoneyCurrency::normalize($row['currency']);
            $grandTotals[$rowCurrency->value] = bcadd($grandTotals[$rowCurrency->value] ?? '0.00', $amount, 2);

            $status = 'در انتظار';

            if ($apply) {
                try {
                    $auditService->applyClawback($row);
                    $status = 'کسر شد';
                    $applied++;
                } catch (\Throwable $exception) {
                    $status = 'خطا: '.$exception->getMessage();
                }
            }

            $tableRows[] = [
                $agent->id,
                $agent->full_name ?? '—',
                $account->id,
                $account->remote_username,
                format_money($row['actual_margin'], $rowCurrency),
                format_money($row['expected_margin'], $rowCurrency),
                format_money($amount, $rowCurrency),
                $status,
            ];
        }

        $this->table(
            ['شناسه نماینده', 'نام', 'شناسه اکانت', 'کاربر', 'پورسانت ثبت‌شده', 'پورسانت صحیح', 'مازاد', 'وضعیت'],
            $tableRows,
        );

        $this->newLine();
        $this->info('تعداد مورد: '.$rows->count());
        $this->info('جمع مازاد: '.collect($grandTotals)->map(fn (string $amount, string $code): string => format_money($amount, $code))->implode(' + '));

        if ($apply) {
            $days = max(1, (int) $this->option('days'));
            $noticeService->activateNoticePeriod($days);
            $this->info("{$applied} مورد اعمال شد.");
            $this->info("منوی «تصحیح حسابداری» در پنل نماینده تا {$days} روز فعال می‌ماند.");
        } else {
            $this->warn('برای اعمال: php artisan accounting:audit-agent-margins --apply');
        }

        return self::SUCCESS;
    }
}
