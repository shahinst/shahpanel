<?php

namespace App\Enums;

enum BroadcastAudience: string
{
    case Agents = 'agents';
    case Sellers = 'sellers';
    case AgentSellers = 'agent_sellers';

    public function label(): string
    {
        return match ($this) {
            self::Agents => 'نمایندگان',
            self::Sellers => 'فروشندگان',
            self::AgentSellers => 'فروشندگان این نماینده',
        };
    }
}
