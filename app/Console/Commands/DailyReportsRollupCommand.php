<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Console\Command;

class DailyReportsRollupCommand extends Command
{
    protected $signature = 'reports:daily-rollup';

    protected $description = 'Aggregate daily metrics into settings for dashboard reporting';

    public function handle(): int
    {
        $date = now()->toDateString();
        $prefix = "report.{$date}";

        $newAccounts = Account::query()
            ->whereDate('created_at', $date)
            ->count();

        $activeAccounts = Account::query()
            ->where('status', AccountStatus::Active)
            ->count();

        $revenue = (string) Invoice::query()
            ->whereDate('issued_at', $date)
            ->where('status', InvoiceStatus::Paid)
            ->sum('total');

        $transactions = Transaction::query()
            ->whereDate('created_at', $date)
            ->count();

        Setting::setValue("{$prefix}.new_accounts", (string) $newAccounts);
        Setting::setValue("{$prefix}.active_accounts", (string) $activeAccounts);
        Setting::setValue("{$prefix}.revenue", $revenue);
        Setting::setValue("{$prefix}.transactions", (string) $transactions);
        Setting::setValue('report.latest_date', $date);

        $this->info("Daily rollup saved for {$date}");
        $this->table(
            ['Metric', 'Value'],
            [
                ['New accounts', $newAccounts],
                ['Active accounts', $activeAccounts],
                ['Revenue', $revenue],
                ['Transactions', $transactions],
            ]
        );

        return self::SUCCESS;
    }
}
