<?php

namespace App\Services;

use App\Enums\AccountCategory;
use App\Enums\AccountStatus;
use App\Enums\MoneyCurrency;
use App\Enums\PaymentRequestStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\PaymentRequest;
use App\Models\Server;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

class DashboardStatsService
{
    /**
     * @return array<string, mixed>
     */
    public function forAdmin(?User $user = null): array
    {
        $wallet = panel_wallet_info($user);

        $revenue = $this->adminRevenueOverview();

        return array_merge($this->baseCards([
            'agents' => User::query()->role(UserRole::Agent)->count(),
            'servers' => Server::query()->count(),
            'active_servers' => Server::query()->active()->count(),
            'pending_payments' => PaymentRequest::query()->where('status', PaymentRequestStatus::Pending)->count(),
            'wallet_balance' => $wallet['balance'],
            'wallet_infinite' => $wallet['infinite'],
            'wallet_currency' => $wallet['currency'],
            'total_catalog_sales_by_currency' => $revenue['total_catalog_sales_by_currency'],
            'total_agent_margin_by_currency' => $revenue['total_agent_margin_by_currency'],
        ], Account::query()), [
            'panel' => 'admin',
        ]);
    }

    /**
     * Historical admin revenue from ledger — never recalculated from current package prices.
     *
     * @return array{total_catalog_sales_by_currency: array<string, string>, total_agent_margin_by_currency: array<string, string>}
     */
    public function adminRevenueOverview(): array
    {
        $revenueGross = $this->ledgerSumByCurrency(
            Transaction::query()->where('type', TransactionType::Revenue)
        );

        $revenueReversed = $this->ledgerSumByCurrency(
            Transaction::query()
                ->where('type', TransactionType::Refund)
                ->where('description', 'Account refund — admin revenue reversed')
        );

        $marginGross = $this->ledgerSumByCurrency(
            Transaction::query()->where('type', TransactionType::Margin)
        );

        $marginReversed = $this->ledgerSumByCurrency(
            Transaction::query()
                ->where('type', TransactionType::Refund)
                ->where('description', 'Account refund — agent commission reversed')
        );

        // مبالغ به تفکیک ارز نگه داشته می‌شوند؛ جمع کردن ارزهای متفاوت در یک عدد بی‌معنا است.
        return [
            'total_catalog_sales_by_currency' => $this->subtractByCurrency($revenueGross, $revenueReversed),
            'total_agent_margin_by_currency' => $this->subtractByCurrency($marginGross, $marginReversed),
        ];
    }

    /**
     * جمع مبلغ تراکنش‌ها به تفکیک ستون currency.
     *
     * @param  Builder<Transaction>  $query
     * @return array<string, string>
     */
    protected function ledgerSumByCurrency(Builder $query): array
    {
        $totals = [];

        $rows = $query
            ->selectRaw('currency, SUM(amount) as total_amount')
            ->groupBy('currency')
            ->get();

        foreach ($rows as $row) {
            $code = MoneyCurrency::normalize($row->currency)->value;
            $amount = number_format((float) $row->total_amount, 2, '.', '');
            $totals[$code] = isset($totals[$code]) ? bcadd($totals[$code], $amount, 2) : $amount;
        }

        return $totals;
    }

    /**
     * کسر برگشتی‌ها از ناخالص، ارز به ارز. خروجی هرگز منفی نمی‌شود.
     *
     * @param  array<string, string>  $gross
     * @param  array<string, string>  $reversed
     * @return array<string, string>
     */
    protected function subtractByCurrency(array $gross, array $reversed): array
    {
        $result = [];

        foreach (array_unique(array_merge(array_keys($gross), array_keys($reversed))) as $code) {
            $net = bcsub($gross[$code] ?? '0.00', $reversed[$code] ?? '0.00', 2);
            $result[$code] = bccomp($net, '0.00', 2) >= 0 ? $net : '0.00';
        }

        if ($result === []) {
            $result[MoneyCurrency::default()->value] = '0.00';
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function forAgent(User $user): array
    {
        $accountQuery = Account::query()->ownedByHierarchy($user);

        $wallet = panel_wallet_info($user);

        return array_merge($this->baseCards([
            'sellers' => User::query()->where('parent_id', $user->id)->role(UserRole::Seller)->count(),
            'pending_payments' => PaymentRequest::query()->ownedByHierarchy($user)->where('status', PaymentRequestStatus::Pending)->count(),
            'wallet_balance' => $wallet['balance'],
            'wallet_infinite' => $wallet['infinite'],
            'wallet_currency' => $wallet['currency'],
        ], $accountQuery), [
            'panel' => 'agent',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forSeller(User $user): array
    {
        $accountQuery = Account::query()->where('owner_seller_id', $user->id);

        $wallet = panel_wallet_info($user);

        return array_merge($this->baseCards([
            'pending_payments' => PaymentRequest::query()->where('requester_user_id', $user->id)->where('status', PaymentRequestStatus::Pending)->count(),
            'wallet_balance' => $wallet['balance'],
            'wallet_infinite' => $wallet['infinite'],
            'wallet_currency' => $wallet['currency'],
            'transactions' => Transaction::query()->where('user_id', $user->id)->count(),
        ], $accountQuery), [
            'panel' => 'seller',
        ]);
    }

    /**
     * @param  array<string, int|string>  $cards
     * @return array<string, mixed>
     */
    protected function baseCards(array $cards, Builder $accountQuery): array
    {
        $totalAccounts = (clone $accountQuery)->count();

        return array_merge($cards, [
            'accounts' => $totalAccounts,
            'accounts_active' => (clone $accountQuery)->where('status', AccountStatus::Active)->count(),
            'accounts_wireguard' => (clone $accountQuery)->inCategory(AccountCategory::Wireguard)->count(),
            'accounts_ppp' => (clone $accountQuery)->inCategory(AccountCategory::Ppp)->count(),
            'accounts_v2ray' => (clone $accountQuery)->inCategory(AccountCategory::V2ray)->count(),
            'accounts_anyconnect' => (clone $accountQuery)->inCategory(AccountCategory::Anyconnect)->count(),
            'charts' => $this->chartPayload($accountQuery),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function chartPayload(Builder $accountQuery): array
    {
        $categoryLabelMap = [
            'wireguard' => AccountCategory::Wireguard->label(),
            'ppp' => AccountCategory::Ppp->label(),
            'v2ray' => AccountCategory::V2ray->label(),
            'anyconnect' => AccountCategory::Anyconnect->label(),
        ];

        $statusLabels = [];
        $statusValues = [];
        $statusByCategory = [];
        $statusBuckets = [];

        $statusRows = (clone $accountQuery)
            ->selectRaw('status, service_type, COUNT(*) as aggregate_count')
            ->groupBy('status', 'service_type')
            ->get();

        foreach ($statusRows as $row) {
            $statusKey = $row->status instanceof AccountStatus
                ? $row->status->value
                : (string) $row->status;

            if ($statusKey === '') {
                continue;
            }

            if (! isset($statusBuckets[$statusKey])) {
                $statusBuckets[$statusKey] = [
                    'total' => 0,
                    'wireguard' => 0,
                    'ppp' => 0,
                    'v2ray' => 0,
                ];
            }

            $count = (int) ($row->aggregate_count ?? 0);
            $statusBuckets[$statusKey]['total'] += $count;

            $category = $row->service_type?->accountCategory()?->value;
            if ($category !== null && array_key_exists($category, $statusBuckets[$statusKey])) {
                $statusBuckets[$statusKey][$category] += $count;
            }
        }

        foreach (AccountStatus::cases() as $status) {
            $bucket = $statusBuckets[$status->value] ?? null;
            $count = (int) ($bucket['total'] ?? 0);

            if ($count <= 0) {
                continue;
            }

            $statusLabels[] = $this->statusLabel($status);
            $statusValues[] = $count;
            $statusByCategory[] = [
                'wireguard' => (int) ($bucket['wireguard'] ?? 0),
                'ppp' => (int) ($bucket['ppp'] ?? 0),
                'v2ray' => (int) ($bucket['v2ray'] ?? 0),
            ];
        }

        $categoryLabels = array_values($categoryLabelMap);
        $categoryValues = [
            (clone $accountQuery)->inCategory(AccountCategory::Wireguard)->count(),
            (clone $accountQuery)->inCategory(AccountCategory::Ppp)->count(),
            (clone $accountQuery)->inCategory(AccountCategory::V2ray)->count(),
        ];

        $days = collect(range(6, 0))->map(fn (int $i) => now()->subDays($i)->startOfDay());
        $trend = $this->dailyCreatedTrend($accountQuery, $days, $categoryLabelMap);

        return [
            'status' => [
                'labels' => $statusLabels,
                'values' => $statusValues,
                'by_category' => $statusByCategory,
            ],
            'category' => ['labels' => $categoryLabels, 'values' => $categoryValues],
            'trend' => [
                'labels' => $trend['labels'],
                'values' => $trend['values'],
                'by_category' => $trend['by_category'],
                'details' => $trend['details'],
                'category_labels' => $categoryLabelMap,
            ],
        ];
    }

    /**
     * 7-day created-account trend with category totals and server/package detail for click popup.
     *
     * @param  \Illuminate\Support\Collection<int, Carbon>  $days
     * @param  array{wireguard: string, ppp: string, v2ray: string}  $categoryLabelMap
     * @return array{
     *     labels: list<string>,
     *     values: list<int>,
     *     by_category: list<array{total: int, wireguard: int, ppp: int, v2ray: int}>,
     *     details: list<array<string, mixed>>
     * }
     */
    protected function dailyCreatedTrend(Builder $accountQuery, $days, array $categoryLabelMap): array
    {
        $from = $days->first()?->copy()->startOfDay() ?? now()->subDays(6)->startOfDay();
        $to = $days->last()?->copy()->endOfDay() ?? now()->endOfDay();

        $emptyCategory = static fn (): array => [
            'total' => 0,
            'wireguard' => 0,
            'ppp' => 0,
            'v2ray' => 0,
        ];

        /** @var array<string, array{total: int, wireguard: int, ppp: int, v2ray: int, groups: array<string, array{server: string, package: string, category: string, count: int}>}> $byDay */
        $byDay = [];
        foreach ($days as $day) {
            $byDay[$day->toDateString()] = array_merge($emptyCategory(), ['groups' => []]);
        }

        $rows = (clone $accountQuery)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as day_key, service_type, server_id, package_id, COUNT(*) as aggregate_count')
            ->groupByRaw('DATE(created_at), service_type, server_id, package_id')
            ->get();

        $serverIds = $rows->pluck('server_id')->filter()->unique()->values()->all();
        $packageIds = $rows->pluck('package_id')->filter()->unique()->values()->all();

        $serverNames = $serverIds === []
            ? collect()
            : Server::query()->whereIn('id', $serverIds)->pluck('name', 'id');
        $packageNames = $packageIds === []
            ? collect()
            : \App\Models\Package::query()->whereIn('id', $packageIds)->pluck('name', 'id');

        foreach ($rows as $row) {
            $dayKey = $row->day_key ? Carbon::parse((string) $row->day_key)->toDateString() : null;

            if ($dayKey === null || ! isset($byDay[$dayKey])) {
                continue;
            }

            $count = (int) ($row->aggregate_count ?? 0);
            $serviceType = $row->service_type instanceof \App\Enums\ServiceType
                ? $row->service_type
                : \App\Enums\ServiceType::tryFrom((string) $row->service_type);
            $category = $serviceType?->accountCategory()?->value ?? 'unknown';

            if (array_key_exists($category, $byDay[$dayKey])) {
                $byDay[$dayKey][$category] += $count;
            }

            $byDay[$dayKey]['total'] += $count;

            $serverName = $serverNames[(int) $row->server_id] ?? __('accounts.unknown_server');
            $packageName = $packageNames[(int) $row->package_id] ?? __('accounts.unknown_package');
            $groupKey = $category.'|'.$serverName.'|'.$packageName;

            if (! isset($byDay[$dayKey]['groups'][$groupKey])) {
                $byDay[$dayKey]['groups'][$groupKey] = [
                    'category' => $category,
                    'server' => $serverName,
                    'package' => $packageName,
                    'count' => 0,
                ];
            }

            $byDay[$dayKey]['groups'][$groupKey]['count'] += $count;
        }

        $labels = [];
        $values = [];
        $byCategory = [];
        $details = [];

        foreach ($days as $day) {
            $dayKey = $day->toDateString();
            $bucket = $byDay[$dayKey];
            $label = jalali_date($day, 'm/d');

            $labels[] = $label;
            $values[] = (int) $bucket['total'];
            $byCategory[] = [
                'total' => (int) $bucket['total'],
                'wireguard' => (int) $bucket['wireguard'],
                'ppp' => (int) $bucket['ppp'],
                'v2ray' => (int) $bucket['v2ray'],
            ];

            $categoryBlocks = [];
            foreach (['wireguard', 'ppp', 'v2ray'] as $categoryKey) {
                $items = collect($bucket['groups'])
                    ->filter(fn (array $group): bool => ($group['category'] ?? '') === $categoryKey)
                    ->sortByDesc('count')
                    ->values()
                    ->map(fn (array $group): array => [
                        'server' => $group['server'],
                        'package' => $group['package'],
                        'count' => (int) $group['count'],
                    ])
                    ->all();

                $categoryBlocks[$categoryKey] = [
                    'label' => $categoryLabelMap[$categoryKey],
                    'total' => (int) $bucket[$categoryKey],
                    'items' => $items,
                ];
            }

            $details[] = [
                'date' => $dayKey,
                'label' => $label,
                'jalali' => jalali_date($day, 'Y/m/d'),
                'total' => (int) $bucket['total'],
                'categories' => $categoryBlocks,
            ];
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'by_category' => $byCategory,
            'details' => $details,
        ];
    }

    protected function statusLabel(AccountStatus $status): string
    {
        return match ($status) {
            AccountStatus::Active => __('accounts.status_active'),
            AccountStatus::Disabled => __('accounts.status_disabled'),
            AccountStatus::Expired => __('accounts.status_expired'),
            AccountStatus::Exhausted => __('accounts.status_exhausted'),
            AccountStatus::Pending => __('accounts.status_pending'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function safeForAdmin(?User $user = null): array
    {
        return $this->safeStats(fn (): array => $this->forAdmin($user), 'admin', $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function safeForAgent(User $user): array
    {
        return $this->safeStats(fn (): array => $this->forAgent($user), 'agent', $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function safeForSeller(User $user): array
    {
        return $this->safeStats(fn (): array => $this->forSeller($user), 'seller', $user);
    }

    /**
     * @param  callable(): array<string, mixed>  $builder
     * @return array<string, mixed>
     */
    protected function safeStats(callable $builder, string $panel, ?User $user = null): array
    {
        try {
            return $builder();
        } catch (Throwable $exception) {
            report($exception);

            return $this->fallbackStats($panel, $user);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function fallbackStats(string $panel, ?User $user = null): array
    {
        $wallet = panel_wallet_info($user);

        return [
            'panel' => $panel,
            'accounts' => 0,
            'accounts_active' => 0,
            'accounts_wireguard' => 0,
            'accounts_ppp' => 0,
            'accounts_v2ray' => 0,
            'accounts_anyconnect' => 0,
            'wallet_balance' => $wallet['balance'],
            'wallet_infinite' => $wallet['infinite'],
            'wallet_currency' => $wallet['currency'],
            'charts' => [
                'status' => ['labels' => [], 'values' => [], 'by_category' => []],
                'category' => ['labels' => [], 'values' => []],
                'trend' => [
                    'labels' => [],
                    'values' => [],
                    'by_category' => [],
                    'details' => [],
                    'category_labels' => [
                        'wireguard' => AccountCategory::Wireguard->label(),
                        'ppp' => AccountCategory::Ppp->label(),
                        'v2ray' => AccountCategory::V2ray->label(),
            'anyconnect' => AccountCategory::Anyconnect->label(),
                    ],
                ],
            ],
        ];
    }
}
