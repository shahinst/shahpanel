<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManagesSupportTickets;
use App\Http\Controllers\Controller;

class SupportTicketController extends Controller
{
    use ManagesSupportTickets;

    protected function supportPanel(): string
    {
        return 'admin';
    }
}
