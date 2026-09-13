<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Cancelled = 'cancelled';
}
