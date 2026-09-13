<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\PackageDuration;
use App\Services\ClientDisplayPricingService;
use App\Services\ClientPurchaseService;
use App\Services\EndUserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShopController extends Controller
{
    public function index(Request $request, ClientDisplayPricingService $pricingService, EndUserService $endUserService): View|RedirectResponse
    {
        try {
            $portal = $endUserService->portalOwnerContext($request->user());
            $catalog = $pricingService->catalogForClient($request->user());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('client.dashboard')
                ->with('error', $exception->getMessage());
        }

        return view('client.shop.index', [
            'catalog' => $catalog,
            'owner' => $portal['owner'],
            'ownerRoleLabel' => $portal['owner_role_label'],
            'cards' => $portal['payment_cards'],
        ]);
    }

    public function store(Request $request, ClientPurchaseService $purchaseService): RedirectResponse
    {
        $validated = $request->validate([
            'package_duration_id' => ['required', 'integer', 'exists:package_durations,id'],
            'data_gb' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $duration = PackageDuration::query()
            ->with('package')
            ->where('is_enabled', true)
            ->whereHas('package', fn ($query) => $query->where('is_active', true))
            ->findOrFail((int) $validated['package_duration_id']);

        if ($duration->package === null) {
            return back()->with('error', __('packages.duration_not_available'));
        }

        $gb = isset($validated['data_gb']) ? (float) $validated['data_gb'] : null;

        try {
            $account = $purchaseService->purchase($request->user(), $duration->package, $duration, $gb);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('client.accounts.show', $account)
            ->with('success', __('clients.purchase_success'));
    }
}
