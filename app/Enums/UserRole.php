<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case Seller = 'seller';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('roles.admin'),
            self::Agent => __('roles.agent'),
            self::Seller => __('roles.seller'),
            self::Client => __('roles.client'),
        };
    }

    public function canManage(self $target): bool
    {
        return match ($this) {
            self::Admin => true,
            self::Agent => in_array($target, [self::Seller, self::Client], true),
            self::Seller => $target === self::Client,
            self::Client => false,
        };
    }
}
