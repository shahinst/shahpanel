<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Bulk package pricing tool: apply a percentage increase/decrease to the price
 * of the selected packages' durations. Prices are rounded to a clean value
 * (e.g. 10,294 → 10,300). In discount mode these are the retail (client) prices,
 * so agent/seller prices scale automatically via their discount tiers.
 */
class PackagePricingController extends Controller
{
    public function edit(Request $request): View
    {
        $this->authorize('viewAny', Package::class);

        $packages = Package::query()
            ->with(['durations' => fn ($q) => $q->orderBy('sort_order'), 'category'])
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('admin.packages.pricing', [
            'activePackages' => $packages->where('is_active', true)->values(),
            'inactivePackages' => $packages->where('is_active', false)->values(),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Package::class);

        $validated = $request->validate([
            'percent' => ['required', 'numeric', 'min:0.01', 'max:1000'],
            'direction' => ['required', 'in:increase,decrease'],
            'package_ids' => ['required', 'array', 'min:1'],
            'package_ids.*' => ['integer', 'exists:packages,id'],
        ], [
            'package_ids.required' => 'حداقل یک پکیج را انتخاب کنید.',
            'percent.required' => 'درصد را وارد کنید.',
        ]);

        $percent = (float) $validated['percent'];
        $factor = $validated['direction'] === 'increase'
            ? 1 + $percent / 100
            : 1 - $percent / 100;

        $packageIds = array_map('intval', $validated['package_ids']);

        $changed = 0;
        $pkgCount = 0;
        $audit = [];

        DB::transaction(function () use ($packageIds, $factor, &$changed, &$pkgCount, &$audit): void {
            $packages = Package::with('durations')->whereIn('id', $packageIds)->get();

            foreach ($packages as $package) {
                $touched = false;

                foreach ($package->durations as $duration) {
                    $old = (float) $duration->price;

                    if ($old <= 0) {
                        continue;
                    }

                    $new = $this->roundPrice($old * $factor);

                    // Never zero-out or invert a price.
                    if ($new <= 0 || abs($new - $old) < 0.01) {
                        continue;
                    }

                    $audit[] = [
                        'package' => $package->name,
                        'package_id' => $package->id,
                        'duration_id' => $duration->id,
                        'old' => $old,
                        'new' => $new,
                    ];

                    $duration->update(['price' => $new]);
                    $changed++;
                    $touched = true;
                }

                if ($touched) {
                    $pkgCount++;
                }
            }
        });

        Log::info('package pricing bulk adjust', [
            'admin_id' => $request->user()?->id,
            'percent' => $percent,
            'direction' => $validated['direction'],
            'packages_changed' => $pkgCount,
            'durations_changed' => $changed,
            'changes' => $audit,
        ]);

        if ($changed === 0) {
            return redirect()
                ->route('admin.packages.pricing')
                ->with('warning', 'هیچ قیمتی تغییر نکرد (پکیج‌های انتخابی قیمت معتبر نداشتند).');
        }

        return redirect()
            ->route('admin.packages.pricing')
            ->with('success', "قیمت {$pkgCount} پکیج ({$changed} مورد قیمت) با موفقیت به‌روزرسانی شد.");
    }

    /**
     * Generate 3/6/12-month prices from each selected package's 1-month price
     * using per-tier multipliers, then enable those durations. In "fill" mode a
     * duration that is already priced AND enabled is left untouched (so manually
     * set prices are never silently changed); "overwrite" recomputes all.
     */
    public function applyDurations(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Package::class);

        $validated = $request->validate([
            'mult_3m' => ['required', 'numeric', 'min:0', 'max:1000'],
            'mult_6m' => ['required', 'numeric', 'min:0', 'max:1000'],
            'mult_1y' => ['required', 'numeric', 'min:0', 'max:1000'],
            'mode' => ['required', 'in:fill,overwrite'],
            'package_ids' => ['required', 'array', 'min:1'],
            'package_ids.*' => ['integer', 'exists:packages,id'],
        ], [
            'package_ids.required' => 'حداقل یک پکیج را انتخاب کنید.',
        ]);

        $mult = [
            '3m' => (float) $validated['mult_3m'],
            '6m' => (float) $validated['mult_6m'],
            '1y' => (float) $validated['mult_1y'],
        ];
        $overwrite = $validated['mode'] === 'overwrite';
        $ids = array_map('intval', $validated['package_ids']);

        $changed = 0;
        $pkgCount = 0;
        $skippedNoBase = 0;
        $audit = [];

        DB::transaction(function () use ($ids, $mult, $overwrite, &$changed, &$pkgCount, &$skippedNoBase, &$audit): void {
            $packages = Package::with('durations')->whereIn('id', $ids)->get();

            foreach ($packages as $package) {
                $byTier = [];
                foreach ($package->durations as $duration) {
                    $byTier[$duration->tier->value] = $duration;
                }

                $one = $byTier['1m'] ?? null;
                if ($one === null || (float) $one->price <= 0) {
                    $skippedNoBase++;

                    continue;
                }

                $base = (float) $one->price;
                $touched = false;

                foreach (['3m', '6m', '1y'] as $tier) {
                    $duration = $byTier[$tier] ?? null;
                    if ($duration === null) {
                        continue; // no such duration row on this package
                    }

                    $alreadySet = (float) $duration->price > 0 && $duration->is_enabled;
                    if ($alreadySet && ! $overwrite) {
                        continue;
                    }

                    $new = $this->roundPrice($base * $mult[$tier]);
                    if ($new <= 0) {
                        continue;
                    }

                    $audit[] = [
                        'package' => $package->name,
                        'package_id' => $package->id,
                        'tier' => $tier,
                        'old' => (float) $duration->price,
                        'new' => $new,
                        'was_enabled' => (bool) $duration->is_enabled,
                    ];

                    $duration->update(['price' => $new, 'is_enabled' => true]);
                    $changed++;
                    $touched = true;
                }

                if ($touched) {
                    $pkgCount++;
                }
            }
        });

        Log::info('package duration pricing generate', [
            'admin_id' => $request->user()?->id,
            'multipliers' => $mult,
            'mode' => $validated['mode'],
            'packages_changed' => $pkgCount,
            'durations_changed' => $changed,
            'skipped_no_1m_price' => $skippedNoBase,
            'changes' => $audit,
        ]);

        if ($changed === 0) {
            return redirect()
                ->route('admin.packages.pricing')
                ->with('warning', 'هیچ دوره‌ای تغییر نکرد (یا همه از قبل تنظیم بودند یا قیمت ۱ماهه نداشتند).');
        }

        return redirect()
            ->route('admin.packages.pricing')
            ->with('success', "قیمت دوره‌ای برای {$pkgCount} پکیج ({$changed} دوره) محاسبه، ثبت و فعال شد.");
    }

    /**
     * Round a price to a clean value so users never see ugly amounts.
     * Step scales with magnitude: ≥100,000 → nearest 1,000 | ≥1,000 → nearest 100 | else → nearest 50.
     * Example: 10,294 → 10,300.
     */
    private function roundPrice(float $value): float
    {
        if ($value <= 0) {
            return 0.0;
        }

        if ($value >= 100000) {
            $step = 1000;
        } elseif ($value >= 1000) {
            $step = 100;
        } else {
            $step = 50;
        }

        return round($value / $step) * $step;
    }
}
