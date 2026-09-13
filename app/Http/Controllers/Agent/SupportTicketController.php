<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Concerns\ManagesSupportTickets;
use App\Http\Controllers\Controller;

class SupportTicketController extends Controller
{
    use ManagesSupportTickets;

    protected function supportPanel(): string
    {
        return 'agent';
    }
}
