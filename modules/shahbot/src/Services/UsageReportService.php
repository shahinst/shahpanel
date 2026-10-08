<?php

namespace Modules\ShahBot\Services;

use App\Models\Account;
use App\Models\AccountUsageLog;

/**
 * Daily usage of an account over the last days, drawn as text bars a chat
 * can show. Built from the usage log the panel's sync already writes; an
 * account whose server reports only a running total has no daily history,
 * and then only the total is shown.
 */
class UsageReportService
{
    public const DAYS = 7;

    public const BAR = 12;

    /**
     * @return array<string, int> Y-m-d => bytes, oldest first
     */
    public function daily(Account $account, int $days = self::DAYS): array
    {
        $from = now()->startOfDay()->subDays($days - 1);
        $totals = [];

        for ($i = 0; $i < $days; $i++) {
            $totals[$from->copy()->addDays($i)->toDateString()] = 0;
        }

        AccountUsageLog::query()
            ->where('account_id', $account->id)
            ->where('recorded_at', '>=', $from)
            ->get(['rx_delta_bytes', 'tx_delta_bytes', 'recorded_at'])
            ->each(function (AccountUsageLog $log) use (&$totals): void {
                $day = $log->recorded_at->toDateString();

                if (isset($totals[$day])) {
                    $totals[$day] += (int) $log->rx_delta_bytes + (int) $log->tx_delta_bytes;
                }
            });

        return $totals;
    }

    public function render(Account $account): string
    {
        $daily = $this->daily($account);
        $peak = max($daily);
        $lines = [];

        if ($peak > 0) {
            foreach ($daily as $day => $bytes) {
                $filled = (int) round($bytes / $peak * self::BAR);
                $lines[] = '<code>'.jalali_date(\Illuminate\Support\Carbon::parse($day), 'm/d').' '.str_repeat('█', $filled).str_repeat('░', self::BAR - $filled).'</code> '.persian_digits(format_data_size($bytes));
            }
        }

        $limit = (int) $account->data_limit_bytes;
        $used = (int) $account->data_used_bytes;

        return __('shahbot::bot.usage_report', [
            'name' => e((string) ($account->display_label ?: $account->remote_username)),
            'chart' => $lines === [] ? __('shahbot::bot.usage_no_history') : implode("\n", $lines),
            'used' => persian_digits(format_data_size($used)),
            'limit' => $limit > 0 ? persian_digits(format_data_size($limit)) : '∞',
            'percent' => $limit > 0 ? persian_digits((int) min(100, floor($used * 100 / $limit))) : '—',
        ]);
    }
}
