<?php

namespace Modules\ShahBot\Services;

use Modules\ShahBot\Models\BotOrder;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotSettings;

/**
 * Loyal customers pay less: the more a customer has spent in the bot, the
 * bigger the fixed discount on every new purchase.
 *
 * The tiers are lines of "amount = percent" in the settings, e.g.
 *   2000000 = 5
 *   5000000 = 10
 * Only the main bot applies them: the discount is paid by the bot's owner,
 * and an agent never agreed to fund the admin's loyalty scheme.
 */
class LoyaltyService
{
    public function __construct(protected BotSettings $settings) {}

    /**
     * @return list<array{min: float, percent: float}> highest first
     */
    public function tiers(): array
    {
        $tiers = [];

        // Persian thousands separators are as likely here as Latin commas.
        $text = str_replace(['٬', '،'], ',', western_digits((string) $this->settings->get('loyalty_tiers')));

        foreach (preg_split('/\R/u', $text) as $line) {
            if (preg_match('/^\s*([\d.,]+)\s*[=:]\s*([\d.]+)\s*%?\s*$/', $line, $m) === 1) {
                $min = (float) str_replace(',', '', $m[1]);
                $percent = min(50.0, (float) $m[2]);

                if ($min > 0 && $percent > 0) {
                    $tiers[] = ['min' => $min, 'percent' => $percent];
                }
            }
        }

        usort($tiers, fn (array $a, array $b): int => $b['min'] <=> $a['min']);

        return $tiers;
    }

    public function spent(BotUser $user): float
    {
        return (float) BotOrder::query()->where('bot_user_id', $user->id)->whereIn('type', ['buy', 'renew'])->sum('amount');
    }

    /**
     * @return array{percent: float, spent: float, next: ?array{min: float, percent: float}}
     */
    public function status(BotUser $user): array
    {
        $spent = (int) $user->bot_id === 0 ? $this->spent($user) : 0.0;
        $percent = 0.0;
        $next = null;

        if ((int) $user->bot_id === 0) {
            foreach ($this->tiers() as $tier) {
                if ($spent >= $tier['min']) {
                    $percent = $tier['percent'];
                    break;
                }

                $next = $tier;
            }
        }

        return ['percent' => $percent, 'spent' => $spent, 'next' => $next];
    }

    public function discount(BotUser $user, string $amount): string
    {
        $percent = $this->status($user)['percent'];

        return number_format($percent > 0 ? round((float) $amount * $percent / 100, 2) : 0, 2, '.', '');
    }
}
