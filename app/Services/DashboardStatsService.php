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
    /** Periods the dashboard offers, in days. */
    public const RANGES = [7, 30, 90];

    protected int $rangeDays = 30;

    /**
     * Pick the period the charts and period KPIs cover. Anything not offered
     * falls back to 30 days.
     */
    public function withRange(int|string|null $days): static
    {
        $days = (int) $days;
        $this->rangeDays = in_array($days, self::RANGES, true) ? $days : 30;

        return $this;
    }

    public function rangeDays(): int
    {
        return $this->rangeDays;
    }
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
        ], Account::query(), 'admin', $user), [
            'panel' => 'admin',
            'top_agents' => $this->topAgents(),
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
        ], $accountQuery, 'agent', $user), [
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
        ], $accountQuery, 'seller', $user), [
            'panel' => 'seller',
        ]);
    }

    /**
     * @param  array<string, int|string>  $cards
     * @return array<string, mixed>
     */
    protected function baseCards(array $cards, Builder $accountQuery, string $panel = 'admin', ?User $user = null): array
    {
        $totalAccounts = (clone $accountQuery)->count();

        return array_merge($cards, [
            'range_days' => $this->rangeDays,
            'period' => $this->periodKpis($accountQuery, $panel, $user),
            'insights' => $this->insights($accountQuery, $panel),
            'accounts' => $totalAccounts,
            'accounts_active' => (clone $accountQuery)->where('status', AccountStatus::Active)->count(),
            'accounts_wireguard' => (clone $accountQuery)->inCategory(AccountCategory::Wireguard)->count(),
            'accounts_ppp' => (clone $accountQuery)->inCategory(AccountCategory::Ppp)->count(),
            'accounts_v2ray' => (clone $accountQuery)->inCategory(AccountCategory::V2ray)->count(),
            'accounts_anyconnect' => (clone $accountQuery)->inCategory(AccountCategory::Anyconnect)->count(),
            'charts' => array_merge($this->chartPayload($accountQuery), [
                'money' => $this->moneyTrend($panel, $user),
            ]),
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
                    'anyconnect' => 0,
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
                'anyconnect' => (int) ($bucket['anyconnect'] ?? 0),
            ];
        }

        $categoryLabels = array_values($categoryLabelMap);
        $categoryValues = [
            (clone $accountQuery)->inCategory(AccountCategory::Wireguard)->count(),
            (clone $accountQuery)->inCategory(AccountCategory::Ppp)->count(),
            (clone $accountQuery)->inCategory(AccountCategory::V2ray)->count(),
        ];

        $days = collect(range($this->rangeDays - 1, 0))->map(fn (int $i) => now()->subDays($i)->startOfDay());
        $trend = $this->dailyCreatedTrend($accountQuery, $days, $categoryLabelMap);

        return [
            'servers' => $this->topGroups($accountQuery, 'server_id', Server::class),
            'packages' => $this->topGroups($accountQuery, 'package_id', \App\Models\Package::class),
            'usage' => $this->usageBuckets($accountQuery),
            'expiring' => $this->expiringSoon($accountQuery),
            'status_keys' => array_values(array_map(
                fn (AccountStatus $status): string => $status->value,
                array_filter(AccountStatus::cases(), fn (AccountStatus $status): bool => (int) ($statusBuckets[$status->value]['total'] ?? 0) > 0),
            )),
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
            'anyconnect' => 0,
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
                'anyconnect' => (int) $bucket['anyconnect'],
            ];

            $categoryBlocks = [];
            foreach (['wireguard', 'ppp', 'v2ray', 'anyconnect'] as $categoryKey) {
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

    /**
     * New accounts and money for the chosen period, with the same figure for
     * the period before it so the cards can show the change.
     *
     * @return array<string, mixed>
     */
    protected function periodKpis(Builder $accountQuery, string $panel, ?User $user): array
    {
        $from = now()->subDays($this->rangeDays)->startOfDay();
        $prevFrom = $from->copy()->subDays($this->rangeDays);

        $newNow = (clone $accountQuery)->where('created_at', '>=', $from)->count();
        $newPrev = (clone $accountQuery)->whereBetween('created_at', [$prevFrom, $from])->count();

        $money = $this->moneyQuery($panel, $user);
        $moneyNow = $money === null ? null : (float) (clone $money)->where('created_at', '>=', $from)->sum('amount');
        $moneyPrev = $money === null ? null : (float) (clone $money)->whereBetween('created_at', [$prevFrom, $from])->sum('amount');

        return [
            'new_accounts' => $newNow,
            'new_accounts_change' => $this->percentChange($newNow, $newPrev),
            'money' => $moneyNow,
            'money_change' => $moneyNow === null ? null : $this->percentChange($moneyNow, (float) $moneyPrev),
            'money_currency' => MoneyCurrency::default()->value,
        ];
    }

    /**
     * Small numbers worth a glance: what expires this week, what is nearly out
     * of data, and how much traffic the accounts used.
     *
     * @return array<string, int>
     */
    protected function insights(Builder $accountQuery, string $panel): array
    {
        $active = (clone $accountQuery)->where('status', AccountStatus::Active);

        return [
            'expiring_7d' => (clone $active)->whereNotNull('expiry_at')->whereBetween('expiry_at', [now(), now()->addDays(7)])->count(),
            'near_quota' => (clone $active)->where('data_limit_bytes', '>', 0)->whereRaw('data_used_bytes >= data_limit_bytes * 0.9')->count(),
            'used_bytes' => (int) (clone $accountQuery)->sum('data_used_bytes'),
            'new_today' => (clone $accountQuery)->where('created_at', '>=', now()->startOfDay())->count(),
        ];
    }

    protected function percentChange(float|int $now, float|int $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return (float) $now === 0.0 ? 0.0 : null;
        }

        return round((($now - $previous) / abs($previous)) * 100, 1);
    }

    /**
     * The ledger rows that make up "income" for this panel, in the panel's
     * own currency: admin revenue, the agent's margins and retail, and what a
     * seller spent on accounts.
     *
     * @return Builder<Transaction>|null
     */
    protected function moneyQuery(string $panel, ?User $user): ?Builder
    {
        $query = Transaction::query()->where('currency', MoneyCurrency::default()->value);

        return match ($panel) {
            'admin' => $query->where('type', TransactionType::Revenue),
            'agent' => $user === null ? null : $query->where('user_id', $user->id)
                ->whereIn('type', [TransactionType::Margin, TransactionType::ClientRetail]),
            'seller' => $user === null ? null : $query->where('user_id', $user->id)
                ->whereIn('type', [TransactionType::Purchase, TransactionType::Renewal]),
            default => null,
        };
    }

    /**
     * Daily money series for the period: for the admin revenue and agent
     * margins, for an agent margins and client sales, for a seller spending
     * on purchases and on renewals.
     *
     * @return array{labels: list<string>, series: list<array{key: string, label: string, values: list<float>}>, currency: string}
     */
    protected function moneyTrend(string $panel, ?User $user): array
    {
        $from = now()->subDays($this->rangeDays - 1)->startOfDay();
        $days = collect(range($this->rangeDays - 1, 0))->map(fn (int $i) => now()->subDays($i)->startOfDay());
        $currency = MoneyCurrency::default()->value;

        $definitions = match ($panel) {
            'admin' => [
                'revenue' => [__('dashboard.series.revenue'), fn ($q) => $q->where('type', TransactionType::Revenue)],
                'margin' => [__('dashboard.series.agent_margin'), fn ($q) => $q->where('type', TransactionType::Margin)],
            ],
            'agent' => [
                'margin' => [__('dashboard.series.margin'), fn ($q) => $q->where('user_id', $user?->id)->where('type', TransactionType::Margin)],
                'retail' => [__('dashboard.series.client_sales'), fn ($q) => $q->where('user_id', $user?->id)->where('type', TransactionType::ClientRetail)],
            ],
            default => [
                'purchase' => [__('dashboard.series.purchases'), fn ($q) => $q->where('user_id', $user?->id)->where('type', TransactionType::Purchase)],
                'renewal' => [__('dashboard.series.renewals'), fn ($q) => $q->where('user_id', $user?->id)->where('type', TransactionType::Renewal)],
            ],
        };

        $series = [];

        foreach ($definitions as $key => [$label, $scope]) {
            $rows = $scope(Transaction::query()->where('currency', $currency)->where('created_at', '>=', $from))
                ->selectRaw('DATE(created_at) as day_key, SUM(amount) as total')
                ->groupByRaw('DATE(created_at)')
                ->pluck('total', 'day_key');

            $series[] = [
                'key' => $key,
                'label' => $label,
                'values' => $days->map(fn (Carbon $day): float => round((float) ($rows[$day->toDateString()] ?? 0), 2))->all(),
            ];
        }

        return [
            'labels' => $days->map(fn (Carbon $day): string => jalali_date($day, 'm/d'))->all(),
            'series' => $series,
            'currency' => $currency,
        ];
    }

    /**
     * The eight servers or packages holding the most accounts, split into
     * active and the rest.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return list<array{name: string, active: int, other: int, total: int}>
     */
    protected function topGroups(Builder $accountQuery, string $column, string $model): array
    {
        $rows = (clone $accountQuery)
            ->whereNotNull($column)
            ->selectRaw($column.' as group_id, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active_count', [AccountStatus::Active->value])
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        $names = $model::query()->whereIn('id', $rows->pluck('group_id')->all())->pluck('name', 'id');

        return $rows->map(fn ($row): array => [
            'name' => (string) ($names[(int) $row->group_id] ?? '#'.$row->group_id),
            'active' => (int) $row->active_count,
            'other' => (int) $row->total - (int) $row->active_count,
            'total' => (int) $row->total,
        ])->values()->all();
    }

    /**
     * Active accounts by how much of their data they used.
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    protected function usageBuckets(Builder $accountQuery): array
    {
        $row = (clone $accountQuery)
            ->where('status', AccountStatus::Active)
            ->selectRaw('
                SUM(CASE WHEN data_limit_bytes IS NULL OR data_limit_bytes = 0 THEN 1 ELSE 0 END) as unlimited,
                SUM(CASE WHEN data_limit_bytes > 0 AND data_used_bytes < data_limit_bytes * 0.25 THEN 1 ELSE 0 END) as q1,
                SUM(CASE WHEN data_limit_bytes > 0 AND data_used_bytes >= data_limit_bytes * 0.25 AND data_used_bytes < data_limit_bytes * 0.5 THEN 1 ELSE 0 END) as q2,
                SUM(CASE WHEN data_limit_bytes > 0 AND data_used_bytes >= data_limit_bytes * 0.5 AND data_used_bytes < data_limit_bytes * 0.75 THEN 1 ELSE 0 END) as q3,
                SUM(CASE WHEN data_limit_bytes > 0 AND data_used_bytes >= data_limit_bytes * 0.75 AND data_used_bytes < data_limit_bytes * 0.9 THEN 1 ELSE 0 END) as q4,
                SUM(CASE WHEN data_limit_bytes > 0 AND data_used_bytes >= data_limit_bytes * 0.9 THEN 1 ELSE 0 END) as q5
            ')
            ->first();

        return [
            'labels' => [
                persian_digits('0–25%'),
                persian_digits('25–50%'),
                persian_digits('50–75%'),
                persian_digits('75–90%'),
                persian_digits('90%+'),
                __('dashboard.unlimited'),
            ],
            'values' => [
                (int) ($row->q1 ?? 0),
                (int) ($row->q2 ?? 0),
                (int) ($row->q3 ?? 0),
                (int) ($row->q4 ?? 0),
                (int) ($row->q5 ?? 0),
                (int) ($row->unlimited ?? 0),
            ],
        ];
    }

    /**
     * Active accounts expiring on each of the next 14 days.
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    protected function expiringSoon(Builder $accountQuery): array
    {
        $days = collect(range(0, 13))->map(fn (int $i) => now()->addDays($i)->startOfDay());

        $rows = (clone $accountQuery)
            ->where('status', AccountStatus::Active)
            ->whereBetween('expiry_at', [now()->startOfDay(), now()->addDays(13)->endOfDay()])
            ->selectRaw('DATE(expiry_at) as day_key, COUNT(*) as total')
            ->groupByRaw('DATE(expiry_at)')
            ->pluck('total', 'day_key');

        return [
            'labels' => $days->map(fn (Carbon $day): string => jalali_date($day, 'm/d'))->all(),
            'values' => $days->map(fn (Carbon $day): int => (int) ($rows[$day->toDateString()] ?? 0))->all(),
        ];
    }

    /**
     * Admin only: agents with the most accounts created in the period.
     *
     * @return list<array{name: string, accounts: int, active: int}>
     */
    public function topAgents(): array
    {
        $from = now()->subDays($this->rangeDays)->startOfDay();

        $rows = Account::query()
            ->whereNotNull('owner_agent_id')
            ->where('created_at', '>=', $from)
            ->selectRaw('owner_agent_id, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active_count', [AccountStatus::Active->value])
            ->groupBy('owner_agent_id')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        $users = User::query()->whereIn('id', $rows->pluck('owner_agent_id')->all())->get(['id', 'full_name', 'username'])->keyBy('id');

        return $rows->map(fn ($row): array => [
            'name' => (string) ($users[(int) $row->owner_agent_id]?->full_name ?: $users[(int) $row->owner_agent_id]?->username ?? '#'.$row->owner_agent_id),
            'accounts' => (int) $row->total,
            'active' => (int) $row->active_count,
        ])->values()->all();
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
            'range_days' => $this->rangeDays,
            'period' => ['new_accounts' => 0, 'new_accounts_change' => null, 'money' => null, 'money_change' => null, 'money_currency' => MoneyCurrency::default()->value],
            'insights' => ['expiring_7d' => 0, 'near_quota' => 0, 'used_bytes' => 0, 'new_today' => 0],
            'charts' => [
                'servers' => [],
                'packages' => [],
                'usage' => ['labels' => [], 'values' => []],
                'expiring' => ['labels' => [], 'values' => []],
                'money' => ['labels' => [], 'series' => [], 'currency' => MoneyCurrency::default()->value],
                'status_keys' => [],
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
