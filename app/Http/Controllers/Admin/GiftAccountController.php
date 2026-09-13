<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\GiftAccountService;
use App\Services\PackageCategoryService;
use App\Services\PackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GiftAccountController extends Controller
{
    public function index(PackageCategoryService $categoryService): View
    {
        $agents = User::query()
            ->where('role', UserRole::Agent)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'username']);

        $packages = Package::query()
            ->when($categoryService->isAvailable(), fn ($q) => $q->with('category'))
            ->where('is_active', true)
            ->with(['durations' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $packageGroups = $categoryService->groupPackages($packages);
        $servers = Server::query()->active()->orderBy('name')->get(['id', 'name']);

        $packageDurations = $packages->mapWithKeys(fn (Package $package): array => [
            $package->id => $package->durations->map(fn ($d): array => [
                'id' => $d->id,
                'label' => $d->displayLabel(),
            ])->values()->all(),
        ])->all();

        return view('admin.gift-accounts.index', compact('agents', 'packageGroups', 'servers', 'packageDurations'));
    }

    public function sellers(Request $request, GiftAccountService $giftAccountService): JsonResponse
    {
        $agentIds = collect($request->input('agent_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();

        $sellers = $giftAccountService->sellersForAgents($agentIds)->map(fn (User $seller): array => [
            'id' => $seller->id,
            'full_name' => $seller->full_name,
            'username' => $seller->username,
            'parent_id' => $seller->parent_id,
        ]);

        return response()->json(['sellers' => $sellers]);
    }

    public function store(
        Request $request,
        GiftAccountService $giftAccountService,
        PackageService $packageService,
    ): RedirectResponse {
        $validated = $request->validate([
            'agent_ids' => ['nullable', 'array'],
            'agent_ids.*' => ['integer', 'exists:users,id'],
            'seller_ids' => ['nullable', 'array'],
            'seller_ids.*' => ['integer', 'exists:users,id'],
            'package_id' => ['required', 'exists:packages,id'],
            'package_duration_id' => ['required', 'exists:package_durations,id'],
            'data_gb' => ['nullable', 'numeric', 'min:0.01'],
            'server_id' => ['nullable', 'exists:servers,id'],
            'charge' => ['required', 'numeric', 'min:0'],
            'name_prefix' => ['required', 'string', 'max:200'],
            'expiry_unlimited' => ['nullable', 'boolean'],
            'expiry_jalali' => ['nullable', 'string', 'max:20'],
        ]);

        $agentIds = collect($validated['agent_ids'] ?? [])->map(fn ($id): int => (int) $id)->all();
        $sellerIds = collect($validated['seller_ids'] ?? [])->map(fn ($id): int => (int) $id)->all();
        $recipientIds = collect($agentIds)->merge($sellerIds)->unique()->values()->all();

        $package = Package::query()->findOrFail((int) $validated['package_id']);
        $duration = $packageService->resolveDuration($package, (int) $validated['package_duration_id']);

        if (! app(PackageCategoryService::class)->isPackageAvailableForNewAccounts($package)) {
            return back()->withInput()->with('error', __('packages.package_not_available_for_new_accounts'));
        }

        $unlimitedExpiry = $request->boolean('expiry_unlimited');
        $expiryAt = null;

        if (! $unlimitedExpiry) {
            $expiryAt = parse_jalali_date($validated['expiry_jalali'] ?? null, endOfDay: true);

            if ($expiryAt === null) {
                return back()
                    ->withInput()
                    ->with('error', __('gift_accounts.expiry_required'));
            }
        }

        $dataGb = $package->isElastic()
            ? $package->clampDataGb((float) ($validated['data_gb'] ?? 0))
            : null;

        if ($package->isElastic() && ($dataGb === null || $dataGb <= 0)) {
            return back()->withInput()->with('error', __('packages.elastic_gb_required'));
        }

        $result = $giftAccountService->createForRecipients(
            $request->user(),
            $recipientIds,
            $package,
            $duration,
            money_string((string) $validated['charge']),
            $expiryAt,
            $unlimitedExpiry,
            (string) $validated['name_prefix'],
            isset($validated['server_id']) ? (int) $validated['server_id'] : null,
            $dataGb,
        );

        $createdCount = count($result['created']);
        $skippedCount = count($result['skipped']);
        $failedCount = count($result['failed']);

        if ($createdCount === 0) {
            if ($skippedCount > 0 && $failedCount === 0) {
                return redirect()
                    ->route('admin.gift-accounts.index')
                    ->with('warning', __('gift_accounts.all_skipped'))
                    ->with('gift_skipped', $result['skipped']);
            }

            $firstError = $result['failed'][0]['error']
                ?? $result['skipped'][0]['message']
                ?? __('gift_accounts.create_failed');

            return back()->withInput()->with('error', $firstError);
        }

        $message = __('gift_accounts.created_summary', [
            'created' => persian_digits((string) $createdCount),
            'skipped' => persian_digits((string) $skippedCount),
            'failed' => persian_digits((string) $failedCount),
        ]);

        $redirect = redirect()
            ->route('admin.gift-accounts.index')
            ->with($failedCount > 0 || $skippedCount > 0 ? 'warning' : 'success', $message);

        if ($skippedCount > 0) {
            $redirect->with('gift_skipped', $result['skipped']);
        }

        if ($failedCount > 0) {
            $redirect->with('gift_failures', $result['failed']);
        }

        return $redirect;
    }
}
