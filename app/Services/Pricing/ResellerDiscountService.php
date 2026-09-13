<?php

namespace App\Services\Pricing;

use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Model 3 pricing: a package has ONE fixed retail price; each agent has a
 * discount off retail (their cost), and grants their sellers a smaller discount.
 * Margins are the difference between discount tiers.
 *
 * Everything here is gated by the `pricing_mode` setting: while it is "legacy"
 * (the default) none of this affects the live economics.
 */
class ResellerDiscountService
{
    public function mode(): string
    {
        $mode = (string) (Setting::getValue('pricing_mode', 'legacy') ?: 'legacy');

        return in_array($mode, ['legacy', 'discount'], true) ? $mode : 'legacy';
    }

    public function isDiscountMode(): bool
    {
        return $this->mode() === 'discount';
    }

    /** Agent discount % off retail for a package: per-package override → agent default → 0. */
    public function agentDiscountPercent(User $agent, ?Package $package): string
    {
        $override = $package ? $this->override($agent->id, $package->id) : null;

        if ($override !== null && $override->discount_percent !== null) {
            return $this->clampPercent((string) $override->discount_percent);
        }

        return $this->clampPercent((string) ($agent->reseller_discount_percent ?? '0'));
    }

    /** Seller discount % (the agent's sellers' margin) for a package: override → agent default → 0. */
    public function sellerDiscountPercent(User $agent, ?Package $package): string
    {
        $override = $package ? $this->override($agent->id, $package->id) : null;

        if ($override !== null && $override->seller_discount_percent !== null) {
            return $this->clampPercent((string) $override->seller_discount_percent);
        }

        return $this->clampPercent((string) ($agent->seller_discount_percent ?? '0'));
    }

    /**
     * A specific seller's per-package price override (absolute wholesale unit
     * price), set by their agent from the seller-edit page. Null = no override →
     * caller falls back to the agent's uniform seller discount.
     */
    public function sellerPackagePriceOverride(int $sellerUserId, int $packageId): ?string
    {
        if (! Schema::hasTable('reseller_seller_package_prices')) {
            return null;
        }

        $value = DB::table('reseller_seller_package_prices')
            ->where('seller_user_id', $sellerUserId)
            ->where('package_id', $packageId)
            ->value('wholesale_price');

        return $value === null ? null : (string) $value;
    }

    /**
     * The highest unit price an agent may charge a seller for a package: their own
     * cost grown by the admin-granted markup range, capped at retail. Returns a
     * clean (roundNice) float. $agentUnit is the agent's own unit price.
     */
    public function sellerCeilingUnit(float $agentUnit, float $retailUnit, float $rangePercent): float
    {
        $ceil = $this->roundNice($agentUnit * (1 + $rangePercent / 100));

        return min($retailUnit, $ceil);
    }

    /** Wholesale unit price = retail_unit × (1 − percent/100), rounded to a clean number. */
    public function applyDiscount(string $retailUnit, string $percent): string
    {
        $rate = bcdiv($this->clampPercent($percent), '100', 6);
        $factor = bcsub('1', $rate, 6);
        $raw = (float) bcmul($retailUnit, $factor, 6);

        return number_format($this->roundNice($raw), 2, '.', '');
    }

    /**
     * Round a price to a clean, magnitude-aware value so users never see ugly
     * amounts like 101,243. Step scales with the amount:
     *   ≥ 10,000 → nearest 1,000 | ≥ 1,000 → nearest 100 | else → nearest 50.
     */
    public function roundNice(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        if ($amount >= 10000) {
            $step = 1000;
        } elseif ($amount >= 1000) {
            $step = 100;
        } else {
            $step = 50;
        }

        return round($amount / $step) * $step;
    }

    protected function override(int $agentId, int $packageId): ?object
    {
        return DB::table('reseller_package_discounts')
            ->where('agent_user_id', $agentId)
            ->where('package_id', $packageId)
            ->first();
    }

    protected function clampPercent(string $percent): string
    {
        $value = (float) $percent;
        $value = max(0.0, min(100.0, $value));

        return number_format($value, 4, '.', '');
    }
}
