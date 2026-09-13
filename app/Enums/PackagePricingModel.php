<?php

namespace App\Enums;

enum PackagePricingModel: string
{
    case Fixed = 'fixed';
    case Elastic = 'elastic';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => __('packages.pricing_model_fixed'),
            self::Elastic => __('packages.pricing_model_elastic'),
        };
    }

    public function isElastic(): bool
    {
        return $this === self::Elastic;
    }
}
