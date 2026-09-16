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
            self::Agents => __('backend.broadcast_audience_agents'),
            self::Sellers => __('broadcasts.audience_sellers'),
            self::AgentSellers => __('backend.broadcast_audience_agent_sellers'),
        };
    }
}
