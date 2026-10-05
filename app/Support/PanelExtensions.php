<?php

namespace App\Support;

use App\Models\Account;
use App\Models\User;
use Throwable;

/**
 * Lets a module add entries to the panels' side menu and to an account's
 * actions menu without touching the core views. A module registers a resolver
 * from its service provider; the core only draws what comes back. Deciding
 * who may see an entry is the resolver's job.
 *
 * A failing resolver is reported and skipped, so one broken module never takes
 * a menu down for everyone.
 */
class PanelExtensions
{
    /** @var list<callable(Account, string): ?array> */
    protected static array $accountMenuResolvers = [];

    /** @var list<callable(string): ?array> */
    protected static array $navResolvers = [];

    /** @var list<callable> */
    protected static array $categoryResolvers = [];

    /**
     * @param  callable(Account, string): ?array  $resolver  $prefix is admin, agent or seller
     */
    public static function accountMenuItem(callable $resolver): void
    {
        static::$accountMenuResolvers[] = $resolver;
    }

    /**
     * @param  callable(string): ?array  $resolver  $panel is admin, agent or seller
     */
    public static function navItem(callable $resolver): void
    {
        static::$navResolvers[] = $resolver;
    }

    /**
     * @return list<array{label: string, url: string, icon: string}>
     */
    public static function accountMenuItems(Account $account, string $prefix): array
    {
        return static::collect(static::$accountMenuResolvers, [$account, $prefix]);
    }

    /**
     * @return list<array{label: string, url: string, icon: string, active: bool}>
     */
    /**
     * Narrow which account categories (wireguard, ppp, v2ray, anyconnect)
     * the side menu offers a user.
     *
     * The resolver receives the panel and the signed-in user and returns the
     * category values it allows, or null when it has no opinion. When several
     * resolvers answer, only categories every one of them allows are shown.
     * This is about the menu only; what a user may open is still decided by
     * the account policies.
     */
    public static function accountCategories(callable $resolver): void
    {
        static::$categoryResolvers[] = $resolver;
    }

    /**
     * @param  list<string>  $categories
     * @return list<string>
     */
    public static function allowedAccountCategories(string $panel, ?User $user, array $categories): array
    {
        foreach (static::$categoryResolvers as $resolver) {
            try {
                $allowed = $resolver($panel, $user);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if (is_array($allowed)) {
                $categories = array_values(array_intersect($categories, $allowed));
            }
        }

        return $categories;
    }

    public static function navItems(string $panel): array
    {
        return array_map(
            fn (array $item): array => $item + ['active' => false],
            static::collect(static::$navResolvers, [$panel]),
        );
    }

    /**
     * @param  list<callable>  $resolvers
     * @param  list<mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function collect(array $resolvers, array $args): array
    {
        $items = [];

        foreach ($resolvers as $resolver) {
            try {
                $item = $resolver(...$args);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if (! is_array($item) || blank($item['label'] ?? null) || blank($item['url'] ?? null)) {
                continue;
            }

            $item['icon'] = filled($item['icon'] ?? null) ? $item['icon'] : 'bx-extension';
            $items[] = $item;
        }

        return $items;
    }
}
