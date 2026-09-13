<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Concerns\ManagesClientPortalSettings;
use App\Http\Controllers\Controller;

class ClientPortalSettingsController extends Controller
{
    use ManagesClientPortalSettings;

    protected function clientSettingsPanel(): string
    {
        return 'seller';
    }
}
