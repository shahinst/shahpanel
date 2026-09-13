<?php

namespace App\Enums;

enum TransactionType: string
{
    case Charge = 'charge';
    case Purchase = 'purchase';
    case Renewal = 'renewal';
    case Commission = 'commission';
    case Margin = 'margin';
    case Revenue = 'revenue';
    case Refund = 'refund';
    /** Reverse a refund: charge the owner again and restore commissions. */
    case Reactivation = 'reactivation';
    case Adjustment = 'adjustment';
    /** Agent prepaid financial plan purchase. */
    case FinancialPlanPurchase = 'financial_plan_purchase';
    /** Retail payment received from an end-user (not staff wholesale). */
    case ClientRetail = 'client_retail';
    /** Wholesale cost when provisioning for an end-user sale. */
    case ClientCost = 'client_cost';
}
