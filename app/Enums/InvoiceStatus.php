<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('app.invoice_status_'.$this->value);
    }
}
