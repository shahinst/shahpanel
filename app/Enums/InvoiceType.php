<?php

namespace App\Enums;

enum InvoiceType: string
{
    case NewAccount = 'new_account';
    case Renewal = 'renewal';
    case Adjustment = 'adjustment';
}
