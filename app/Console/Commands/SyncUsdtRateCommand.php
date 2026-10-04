<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\PaymentSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the USDT -> Toman rate current for crypto payments.
 *
 * Prices in the panel are in Toman while NowPayments charges in dollars, so a
 * stale rate either overcharges the customer or pays the seller short. The
 * rate is fetched three times a day from public market APIs; the first one
 * that answers sensibly wins. An admin who prefers a fixed rate turns the
 * automatic update off and the rate they typed stays untouched.
 */
class SyncUsdtRateCommand extends Command
{
    protected $signature = 'panel:sync-usdt-rate {--force : حتی اگر به‌روزرسانی خودکار خاموش باشد}';

    protected $description = 'به‌روزرسانی نرخ تتر به تومان از بازار';

    public const KEY_AUTO = 'usdt_rate_auto';

    public const KEY_UPDATED_AT = 'usdt_rate_updated_at';

    public function handle(): int
    {
        if (! $this->option('force') && Setting::getValue(self::KEY_AUTO, '1') !== '1') {
            $this->line('automatic rate update is off');

            return self::SUCCESS;
        }

        foreach ($this->sources() as $name => $fetch) {
            try {
                $toman = $fetch();
            } catch (Throwable $e) {
                Log::warning('USDT rate source failed', ['source' => $name, 'error' => $e->getMessage()]);

                continue;
            }

            // A price outside this band is a broken answer, not a market move.
            if ($toman === null || $toman < 10000 || $toman > 10000000) {
                continue;
            }

            PaymentSettings::setUsdtTomanRate(number_format($toman, 2, '.', ''));
            Setting::setValue(self::KEY_UPDATED_AT, now()->toIso8601String());
            $this->info("USDT = {$toman} Toman ({$name})");

            return self::SUCCESS;
        }

        $this->error('no rate source answered; the previous rate is kept');

        return self::FAILURE;
    }

    /**
     * @return array<string, callable(): ?float>
     */
    protected function sources(): array
    {
        return [
            'nobitex' => function (): ?float {
                $rls = Http::timeout(15)->get('https://api.nobitex.ir/market/stats', ['srcCurrency' => 'usdt', 'dstCurrency' => 'rls'])
                    ->json('stats.usdt-rls.latest');

                return is_numeric($rls) ? (float) $rls / 10 : null;
            },
            'wallex' => function (): ?float {
                $toman = Http::timeout(15)->get('https://api.wallex.ir/v1/markets')->json('result.symbols.USDTTMN.stats.lastPrice');

                return is_numeric($toman) ? (float) $toman : null;
            },
        ];
    }
}
