<?php

namespace App\Enums;

enum AccountBillingContext: string
{
    /** Staff (agent/seller) purchase: tiered wholesale, margin, invoices. */
    case Staff = 'staff';

    /** End-user self-service: display price on client wallet only; separate owner settlement. */
    case ClientPortal = 'client_portal';
}
