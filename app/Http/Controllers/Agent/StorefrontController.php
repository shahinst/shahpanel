<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Concerns\ManagesStorefrontSettings;
use App\Http\Controllers\Controller;

class StorefrontController extends Controller
{
    use ManagesStorefrontSettings;

    protected function storefrontEditView(): string
    {
        return 'agent.storefront.edit';
    }

    protected function storefrontEditRoute(): string
    {
        return 'agent.storefront.edit';
    }
}
