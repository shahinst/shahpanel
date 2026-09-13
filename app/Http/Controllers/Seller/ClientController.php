<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Concerns\ManagesClients;
use App\Http\Controllers\Concerns\ShowsClientAccountDetails;
use App\Http\Controllers\Controller;

class ClientController extends Controller
{
    use ManagesClients;
    use ShowsClientAccountDetails;

    protected function clientsPanel(): string
    {
        return 'seller';
    }
}
