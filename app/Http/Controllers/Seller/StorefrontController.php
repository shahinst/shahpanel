<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Concerns\ManagesStorefrontSettings;
use App\Http\Controllers\Controller;

class StorefrontController extends Controller
{
    use ManagesStorefrontSettings;

    protected function storefrontEditView(): string
    {
        return 'seller.storefront.edit';
    }

    protected function storefrontEditRoute(): string
    {
        return 'seller.storefront.edit';
    }
}
