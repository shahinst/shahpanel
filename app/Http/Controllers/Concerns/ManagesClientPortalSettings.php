<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\UserRole;
use App\Services\ClientDisplayPricingService;
use App\Services\PackageCategoryService;
use App\Services\PaymentCardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

trait ManagesClientPortalSettings
{
    use ManagesPaymentCards;

    public function editClientPricing(Request $request, ClientDisplayPricingService $pricingService): View
    {
        if (! Schema::hasTable('client_display_prices')) {
            return view('shared.client-portal-settings.migration-required', [
                'panel' => $this->clientSettingsPanel(),
            ]);
        }

        $owner = $request->user();
        $catalog = $pricingService->managementCatalogForOwner($owner);
        $catalogGroups = app(PackageCategoryService::class)->groupCatalogRows($catalog);

        return view('shared.client-portal-settings.pricing', [
            'catalog' => $catalog,
            'catalogGroups' => $catalogGroups,
            'panel' => $this->clientSettingsPanel(),
            'showHierarchyPricingNotice' => in_array($owner->role, [UserRole::Admin, UserRole::Agent], true),
        ]);
    }

    public function updateClientPricing(Request $request, ClientDisplayPricingService $pricingService): RedirectResponse
    {
        $validated = $request->validate([
            'prices' => ['nullable', 'array'],
            'prices.*.display_price' => ['nullable', 'numeric', 'min:0'],
            'prices.*.is_visible' => ['nullable'],
        ]);

        $pricingService->syncForOwner($request->user(), $validated['prices'] ?? []);

        return redirect()
            ->route($this->clientSettingsPanel().'.client-pricing.edit')
            ->with('success', __('app.saved'));
    }

    public function editClientPaymentCard(Request $request, PaymentCardService $cardService): View
    {
        return $this->indexPaymentCards($request, $cardService);
    }

    public function updateClientPaymentCard(Request $request, PaymentCardService $cardService): RedirectResponse
    {
        return $this->storePaymentCard($request, $cardService);
    }

    abstract protected function clientSettingsPanel(): string;
}
