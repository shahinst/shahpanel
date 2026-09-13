<?php

namespace App\Enums;

enum NotificationType: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
    case PaymentRequest = 'payment_request';
    case AccountExpiry = 'account_expiry';
    case ServerSync = 'server_sync';
    case QuotaExhausted = 'quota_exhausted';
    case Broadcast = 'broadcast';
}
