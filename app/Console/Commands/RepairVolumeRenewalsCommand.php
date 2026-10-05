<?php

namespace App\Console\Commands;

use App\Enums\InvoiceType;
use App\Models\Account;
use App\Models\Invoice;
use App\Services\AccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pays back the volume lost to the "upgrade volume" renewal bug fixed in 3.19.0.
 *
 * Before 3.19.0 an upgrade renewal was billed for the whole new volume but
 * kept the old usage on the meter, so a customer who paid for 20 GB with
 * 10 GB already used could reach only 10 GB. accounts:repair-volume-topup does
 * not see this case: it looks for add-volume renewals.
 *
 * An account counts as hit only when all of these hold for its last renewal:
 *  - the renewal invoice was for the account's whole volume (an add-volume
 *    renewal is invoiced for the added gigabytes alone, so it never matches);
 *  - the meter was not cleared afterwards: no usage snapshot after the
 *    renewal fell below the one before it, and usage today is still at or
 *    above it.
 * What is owed is exactly the usage carried over at renewal. It is added on
 * top of the ceiling rather than by zeroing the meter, which would also hand
 * back what was used after the renewal and paid for fairly.
 *
 * Running it twice is safe: after a repair the account's volume is above what
 * was invoiced, so it no longer matches.
 */
class RepairVolumeRenewalsCommand extends Command
{
    protected $signature = 'panel:repair-volume-renewals
        {--apply : جبران را واقعاً اعمال کن (بدون آن فقط گزارش می‌دهد)}
        {--id=* : فقط همین اکانت‌ها}';

    protected $description = 'جبران حجمی که باگ «ارتقای حجم» در تمدید (پیش از ۳.۱۹.۰) از مشتری گرفته بود';

    private const GIB = 1073741824;

    public function handle(AccountService $accounts): int
    {
        $invoices = Invoice::query()->where('type', InvoiceType::Renewal)
            ->when($this->option('id'), fn ($q, $ids) => $q->whereIn('account_id', array_map('intval', $ids)))
            ->orderBy('id')->get(['id', 'account_id', 'created_at'])
            ->keyBy('account_id');

        $owed = [];
        $unclear = 0;

        foreach ($invoices as $invoice) {
            $account = Account::query()->find($invoice->account_id);

            if ($account === null || (int) $account->data_limit_bytes <= 0 || $account->purchased_data_gb === null) {
                continue;
            }

            // The quantity column is the line count; the gigabytes are written
            // into the line's description, e.g. "… (20 GB)".
            $description = (string) DB::table('invoice_items')->where('invoice_id', $invoice->id)->value('description');

            if (! preg_match('/\(\s*([\d.]+)\s*GB\s*\)/i', $description, $m)) {
                $unclear++;

                continue;
            }

            if (abs((float) $m[1] - (float) $account->purchased_data_gb) > 0.05) {
                continue; // already repaired, or changed since
            }

            // The invoice cannot tell the modes apart: an add-volume renewal
            // writes the new total into its line too, so "invoiced for the
            // whole volume" matched every top-up as well. Taken as the signal,
            // it credited the usage of renewals that had lost nothing. The
            // renewal log records the mode itself; nothing but an upgrade
            // qualifies, and a renewal with no log is left alone.
            if ($this->renewalMode($account) !== 'upgrade_volume') {
                continue;
            }

            $logs = DB::table('account_usage_logs')->where('account_id', $account->id);
            $before = (clone $logs)->where('recorded_at', '<=', $invoice->created_at)->orderByDesc('recorded_at')->first();

            if ($before === null) {
                $unclear++;

                continue;
            }

            $carried = (int) $before->rx_snapshot + (int) $before->tx_snapshot;
            $cleared = (clone $logs)->where('recorded_at', '>', $invoice->created_at)
                ->whereRaw('(rx_snapshot + tx_snapshot) < ?', [$carried])->exists();

            if ($carried <= 0 || $cleared || (int) $account->data_used_bytes < $carried) {
                continue;
            }

            $owed[] = [$account, round($carried / self::GIB, 2)];
        }

        $this->info(sprintf('اکانت‌های تمدیدشده: %d · آسیب‌دیده: %d · نامشخص (بی‌داده): %d', $invoices->count(), count($owed), $unclear));

        foreach ($owed as [$account, $gb]) {
            $this->line(sprintf('  #%d %s  حجم %.2f GB  مصرف %.2f GB  جبران +%.2f GB',
                $account->id, $account->remote_username, (float) $account->purchased_data_gb,
                $account->data_used_bytes / self::GIB, $gb));
        }

        if (! $this->option('apply')) {
            $this->comment($owed === [] ? 'چیزی برای جبران نیست.' : 'برای اعمال، دوباره با --apply اجرا کنید.');

            return self::SUCCESS;
        }

        $done = 0;

        foreach ($owed as [$account, $gb]) {
            try {
                $accounts->applyVolumeTopUp($account, $gb, true);
                $done++;
            } catch (Throwable $e) {
                $this->error(sprintf('  #%d: %s', $account->id, $e->getMessage()));
            }
        }

        $this->info(sprintf('جبران شد: %d · ناموفق: %d', $done, count($owed) - $done));

        return $done === count($owed) ? self::SUCCESS : self::FAILURE;
    }

    /** The mode of the account's latest renewal, as the renewal itself logged it. */
    protected function renewalMode(Account $account): ?string
    {
        $payload = DB::table('activity_logs')
            ->where('action', 'account.renewed')
            ->where('entity_type', 'like', '%Account')
            ->where('entity_id', $account->id)
            ->orderByDesc('id')
            ->value('payload');

        $mode = json_decode((string) $payload, true)['renewal_mode'] ?? null;

        return is_string($mode) ? $mode : null;
    }
}
