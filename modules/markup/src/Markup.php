<?php

namespace Modules\Markup;

use App\Enums\UserRole;
use App\Models\PackageDuration;
use App\Models\Setting;
use App\Models\User;
use App\Services\UserPackagePricingService;
use Illuminate\Support\Facades\DB;

/**
 * The seller's price is the agent's own price plus the agent's markup. The
 * admin's share of a sale is the agent's price whatever the markup is, so a
 * markup can only move money between the agent and the seller — never out of
 * the admin's pocket.
 */
class Markup
{
    public const MAX_KEY = 'markup_max_percent';

    public const DEFAULT_MAX = 15.0;

    public static function maxPercent(): float
    {
        return (float) (Setting::getValue(self::MAX_KEY, (string) self::DEFAULT_MAX) ?? self::DEFAULT_MAX);
    }

    /** The seller's own percent if set, else the agent's default; null when neither exists. */
    public static function percentFor(User $seller): ?float
    {
        if ($seller->role !== UserRole::Seller || $seller->parent_id === null) {
            return null;
        }

        $rows = DB::table('agent_markups')
            ->where('agent_user_id', $seller->parent_id)
            ->where(fn ($q) => $q->whereNull('seller_user_id')->orWhere('seller_user_id', $seller->id))
            ->pluck('percent', 'seller_user_id');

        $percent = $rows[$seller->id] ?? $rows[''] ?? null;

        // A later, lower ceiling caps markups saved before it.
        return $percent === null ? null : min((float) $percent, self::maxPercent());
    }

    public static function sellerPrice(User $seller, PackageDuration $duration): ?string
    {
        $percent = self::percentFor($seller);

        if ($percent === null) {
            return null;
        }

        $agent = User::query()->find($seller->parent_id);

        if ($agent === null || $agent->role !== UserRole::Agent) {
            return null;
        }

        $agentPrice = app(UserPackagePricingService::class)->wholesalePriceFor($agent, $duration);

        if ($agentPrice === null) {
            return null;
        }

        return bcadd($agentPrice, bcdiv(bcmul($agentPrice, (string) $percent, 4), '100', 4), 2);
    }
}
