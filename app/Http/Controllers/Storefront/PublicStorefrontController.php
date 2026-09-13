<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Storefront;
use App\Services\ClientDisplayPricingService;
use Illuminate\View\View;

class PublicStorefrontController extends Controller
{
    public function show(string $slug, ClientDisplayPricingService $pricing): View
    {
        $storefront = Storefront::query()
            ->where('slug', $slug)
            ->published()
            ->with('user')
            ->firstOrFail();

        $catalog = $pricing->catalogForOwner($storefront->user)
            ->groupBy(fn (array $row) => $row['package']->id);

        return view('storefront.public', compact('storefront', 'catalog'));
    }
}
