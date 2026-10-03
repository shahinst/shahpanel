<?php

namespace App\Support;

use App\Models\GatewayPayment;
use Throwable;

/**
 * Where a customer lands after paying at an online gateway. By default that is
 * the wallet page of their panel; a module whose customers have no panel
 * login (the Telegram bot) registers a resolver that sends them to a page of
 * its own instead. The first resolver that returns a URL wins.
 */
class GatewayReturnUrls
{
    /** @var list<callable(GatewayPayment, string): ?string> */
    protected static array $resolvers = [];

    /**
     * @param  callable(GatewayPayment, string): ?string  $resolver
     */
    public static function register(callable $resolver): void
    {
        static::$resolvers[] = $resolver;
    }

    public static function for(GatewayPayment $payment, string $status): ?string
    {
        foreach (static::$resolvers as $resolver) {
            try {
                $url = $resolver($payment, $status);
            } catch (Throwable $e) {
                report($e);
                $url = null;
            }

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }
}
