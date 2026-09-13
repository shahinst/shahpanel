<?php

namespace App\Enums;

enum AgentFinancialPlanStatus: string
{
    case Active = 'active';
    case Depleted = 'depleted';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('financial_plans.status_active'),
            self::Depleted => __('financial_plans.status_depleted'),
        };
    }
}
