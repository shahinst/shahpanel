<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;

class GlobalDiscountService
{
    public function isActive(): bool
    {
        if (Setting::getValue('global_discount_enabled', '0') !== '1') {
            return false;
        }

        $percent = (float) Setting::getValue('global_discount_percent', 0);

        if ($percent <= 0) {
            return false;
        }

        $endsAt = Setting::getValue('global_discount_ends_at');

        if ($endsAt !== null && $endsAt !== '') {
            return now()->lte(Carbon::parse($endsAt));
        }

        return true;
    }

    public function percent(): float
    {
        return max(0.0, min(100.0, (float) Setting::getValue('global_discount_percent', 0)));
    }

    public function apply(string $price): string
    {
        if (! $this->isActive()) {
            return number_format((float) $price, 2, '.', '');
        }

        $rate = bcdiv((string) $this->percent(), '100', 4);
        $multiplier = bcsub('1', $rate, 4);
        $discounted = bcmul(number_format((float) $price, 2, '.', ''), $multiplier, 2);

        return bccomp($discounted, '0', 2) < 0 ? '0.00' : $discounted;
    }
}
