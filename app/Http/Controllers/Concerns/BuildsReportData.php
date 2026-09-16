<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AccountStatus;
use App\Enums\InvoiceType;
use App\Enums\MoneyCurrency;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Server;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ExpiringAccountThresholds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Builds the reports dashboard data, scoped to the viewer's role:
 *  - Admin  : the whole system (incl. server breakdown, all resellers).
 *  - Agent  : only their hierarchy (their sellers + those sellers' accounts). No servers.
 *  - Seller : only their own accounts and clients. No servers, no sellers.
 */
trait BuildsReportData
{
    protected function buildReportData(Request $request, User $viewer): array
    {
        $role = $viewer->role;
        $panel = match ($role) {
            UserRole::Agent => 'agent',
            UserRole::Seller => 'seller',
            default => 'admin',
        };
        $isAdmin = $role === UserRole::Admin;
        $isAgent = $role === UserRole::Agent;

        $from = parse_jalali_date($request->input('from')) ?? now()->subDays(30)->startOfDay();
        $to = parse_jalali_date($request->input('to'), endOfDay: true) ?? now()->endOfDay();
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        // ---- scoping helpers ----
        $accounts = function () use ($role, $viewer) {
            $q = Account::query();
            if ($role === UserRole::Agent) {
                $q->where('owner_agent_id', $viewer->id);
            } elseif ($role === UserRole::Seller) {
                $q->where('owner_seller_id', $viewer->id);
            }
            return $q;
        };
        $invoices = function () use ($role, $viewer) {
            $q = Invoice::query();
            if ($role === UserRole::Agent) {
                $q->where('agent_user_id', $viewer->id);
            } elseif ($role === UserRole::Seller) {
                $q->where('seller_user_id', $viewer->id);
            }
            return $q;
        };

        // sellers that belong to this viewer (admin=all, agent=own, seller=none)
        $sellerIds = collect();
        if ($isAdmin) {
            $sellerIds = User::query()->role(UserRole::Seller)->pluck('id');
        } elseif ($isAgent) {
            $sellerIds = User::query()->role(UserRole::Seller)->where('parent_id', $viewer->id)->pluck('id');
        }

        // ==================== PERIOD (revenue from invoices.issued_at) ====================
        // مبالغ بر اساس ارز فاکتور تفکیک می‌شوند؛ جمع‌زدن ارزهای مختلف در یک عدد بی‌معنی است.
        $revTotal = $this->reportSumByCurrency($invoices()->whereBetween('issued_at', [$from, $to]), 'total');
        $revNew = $this->reportSumByCurrency($invoices()->whereBetween('issued_at', [$from, $to])->where('type', InvoiceType::NewAccount), 'total');
        $revRenew = $this->reportSumByCurrency($invoices()->whereBetween('issued_at', [$from, $to])->where('type', InvoiceType::Renewal), 'total');
        $cntRenew = $invoices()->whereBetween('issued_at', [$from, $to])->where('type', InvoiceType::Renewal)->count();
        $newAccounts = $accounts()->whereBetween('created_at', [$from, $to])->count();
        $refundsCount = $accounts()->whereBetween('refunded_at', [$from, $to])->count();

        // margin/commission earned by an agent in the period
        $agentEarned = [];
        if ($isAgent) {
            $agentEarned = $this->reportSumByCurrency(Transaction::query()
                ->where('user_id', $viewer->id)
                ->whereIn('type', [TransactionType::Margin, TransactionType::Commission])
                ->whereBetween('created_at', [$from, $to]), 'amount');
        }
        $adminRevenue = [];
        $agentMarginAll = [];
        if ($isAdmin) {
            $adminRevenue = $this->reportSumByCurrency(Transaction::query()->where('type', TransactionType::Revenue)
                ->whereBetween('created_at', [$from, $to]), 'amount');
            $agentMarginAll = $this->reportSumByCurrency(Transaction::query()->whereIn('type', [TransactionType::Margin, TransactionType::Commission])
                ->whereBetween('created_at', [$from, $to]), 'amount');
        }

        // new sub-users in the period
        $newSellers = $sellerIds->isEmpty() && ! $isAdmin ? 0 : (function () use ($isAdmin, $isAgent, $viewer, $from, $to) {
            $q = User::query()->role(UserRole::Seller)->whereBetween('created_at', [$from, $to]);
            if ($isAgent) {
                $q->where('parent_id', $viewer->id);
            }
            return ($isAdmin || $isAgent) ? $q->count() : 0;
        })();
        $newClients = (function () use ($role, $viewer, $sellerIds, $from, $to) {
            $q = User::query()->role(UserRole::Client)->whereBetween('created_at', [$from, $to]);
            if ($role === UserRole::Seller) {
                $q->where('parent_id', $viewer->id);
            } elseif ($role === UserRole::Agent) {
                $q->whereIn('parent_id', $sellerIds->all() ?: [0]);
            }
            return $q->count();
        })();
        $newAgents = $isAdmin ? User::query()->role(UserRole::Agent)->whereBetween('created_at', [$from, $to])->count() : 0;

        // هر کاربر می‌تواند برای هر ارز یک کیف‌پول جدا داشته باشد؛ پس موجودی هم به تفکیک ارز خوانده می‌شود.
        $myWallet = $this->reportSumByCurrency(Wallet::query()->where('user_id', $viewer->id), 'balance');

        // ---- role-aware KPI cards ----
        $revLabel = $isAdmin
            ? __('backend.report_revenue_total_admin')
            : ($isAgent ? __('backend.report_revenue_total_agent') : __('backend.report_revenue_total_seller'));
        $kpis = [
            ['title' => $revLabel, 'value' => $this->formatReportMoney($revTotal), 'icon' => 'bx-wallet', 'color' => 'success',
                'hint' => __('backend.report_revenue_hint', [
                    'new' => $this->formatReportMoney($revNew),
                    'renew' => $this->formatReportMoney($revRenew),
                ])],
            ['title' => __('backend.report_new_accounts'), 'value' => persian_digits($newAccounts), 'icon' => 'bx-plus-circle', 'color' => 'primary',
                'hint' => __('backend.report_new_accounts_hint', ['count' => persian_digits($cntRenew)])],
            ['title' => __('backend.report_refunds'), 'value' => persian_digits($refundsCount), 'icon' => 'bx-undo', 'color' => 'danger', 'hint' => null],
        ];
        if ($isAdmin) {
            $kpis[] = ['title' => __('backend.report_admin_revenue'), 'value' => $this->formatReportMoney($adminRevenue), 'icon' => 'bx-trending-up', 'color' => 'success',
                'hint' => __('backend.report_admin_revenue_hint', ['amount' => $this->formatReportMoney($agentMarginAll)])];
            $kpis[] = ['title' => __('backend.report_new_agents'), 'value' => persian_digits($newAgents), 'icon' => 'bx-user-pin', 'color' => 'primary', 'hint' => null];
            $kpis[] = ['title' => __('backend.report_new_sellers'), 'value' => persian_digits($newSellers), 'icon' => 'bx-user', 'color' => 'primary', 'hint' => null];
            $kpis[] = ['title' => __('backend.report_new_clients'), 'value' => persian_digits($newClients), 'icon' => 'bx-group', 'color' => 'primary', 'hint' => null];
        } elseif ($isAgent) {
            $kpis[] = ['title' => __('backend.report_agent_profit'), 'value' => $this->formatReportMoney($agentEarned), 'icon' => 'bx-trending-up', 'color' => 'success', 'hint' => null];
            $kpis[] = ['title' => __('backend.report_new_sellers'), 'value' => persian_digits($newSellers), 'icon' => 'bx-user', 'color' => 'primary', 'hint' => null];
            $kpis[] = ['title' => __('backend.report_new_clients'), 'value' => persian_digits($newClients), 'icon' => 'bx-group', 'color' => 'primary', 'hint' => null];
            $kpis[] = ['title' => __('backend.report_my_wallet'), 'value' => $this->formatReportMoney($myWallet), 'icon' => 'bx-credit-card', 'color' => 'warning', 'hint' => null];
        } else { // seller
            $kpis[] = ['title' => __('backend.report_new_clients'), 'value' => persian_digits($newClients), 'icon' => 'bx-group', 'color' => 'primary', 'hint' => null];
            $kpis[] = ['title' => __('backend.report_my_wallet'), 'value' => $this->formatReportMoney($myWallet), 'icon' => 'bx-credit-card', 'color' => 'warning', 'hint' => null];
        }

        // ==================== CURRENT STATE (scoped) ====================
        $totalAccounts = $accounts()->count();
        $byStatus = $accounts()->select('status', DB::raw('COUNT(*) c'))->groupBy('status')->get()
            ->mapWithKeys(fn ($r) => [($r->status instanceof AccountStatus ? $r->status->value : (string) $r->status) => (int) $r->c]);
        $activeAccounts = (int) ($byStatus[AccountStatus::Active->value] ?? 0);
        $byService = $accounts()->select('service_type', DB::raw('COUNT(*) c'))->groupBy('service_type')->orderByDesc('c')->get()
            ->mapWithKeys(fn ($r) => [(is_object($r->service_type) ? $r->service_type->value : (string) $r->service_type) => (int) $r->c]);

        $packageNames = Package::query()->pluck('name', 'id');
        $byPackage = $accounts()->whereNotNull('package_id')->select('package_id', DB::raw('COUNT(*) c'))
            ->groupBy('package_id')->orderByDesc('c')->limit(12)->get()
            ->map(fn ($r) => ['name' => $packageNames[$r->package_id] ?? ('#'.$r->package_id), 'count' => (int) $r->c]);

        $byServer = collect();
        if ($isAdmin) {
            $serverNames = Server::query()->pluck('name', 'id');
            $byServer = $accounts()->whereNotNull('server_id')->select('server_id', DB::raw('COUNT(*) c'))
                ->groupBy('server_id')->orderByDesc('c')->get()
                ->map(fn ($r) => ['name' => $serverNames[$r->server_id] ?? ('#'.$r->server_id), 'count' => (int) $r->c]);
        }

        $days = ExpiringAccountThresholds::days();
        $volBytes = ExpiringAccountThresholds::volumeBytes();
        $expiringSoon = $accounts()->whereNotNull('expiry_at')->whereBetween('expiry_at', [now(), now()->copy()->addDays($days)])->count();
        $lowVolume = $accounts()->whereNotNull('data_limit_bytes')
            ->whereRaw('(data_limit_bytes - COALESCE(data_used_bytes, 0)) < ?', [$volBytes])->count();

        // ==================== RESELLERS (admin: all, agent: own, seller: none) ====================
        $showResellers = $isAdmin || $isAgent;
        $userNames = User::query()->pluck('full_name', 'id');
        $topSellers = collect();
        $topSellersRevenue = collect();
        $sellersCount = 0;
        $walletSellers = [];
        if ($showResellers) {
            $sellersCount = $isAdmin ? User::query()->role(UserRole::Seller)->count() : $sellerIds->count();
            $walletSellers = $this->reportSumByCurrency(Wallet::query()->whereIn('user_id', $sellerIds->all() ?: [0]), 'balance');

            $topSellers = $accounts()->whereNotNull('owner_seller_id')
                ->select('owner_seller_id', DB::raw('COUNT(*) c'), DB::raw("SUM(status='active') a"))
                ->groupBy('owner_seller_id')->orderByDesc('c')->limit(10)->get()
                ->map(fn ($r) => ['name' => $userNames[$r->owner_seller_id] ?? ('#'.$r->owner_seller_id), 'total' => (int) $r->c, 'active' => (int) $r->a]);

            // گردش هر فروشنده به تفکیک ارز نگه داشته می‌شود؛ مرتب‌سازی فقط برای انتخاب ۱۰ فروشنده‌ی برتر است.
            $topSellersRevenue = $invoices()->whereBetween('issued_at', [$from, $to])->whereNotNull('seller_user_id')
                ->select('seller_user_id', 'currency', DB::raw('SUM(total) s'), DB::raw('COUNT(*) c'))
                ->groupBy('seller_user_id', 'currency')->get()
                ->groupBy('seller_user_id')
                ->map(function ($rows, $sellerId) use ($userNames): array {
                    $revenue = [];
                    $count = 0;
                    foreach ($rows as $r) {
                        $code = MoneyCurrency::normalize($r->currency)->value;
                        $revenue[$code] = bcadd($revenue[$code] ?? '0', (string) $r->s, 2);
                        $count += (int) $r->c;
                    }

                    return [
                        'name' => $userNames[$sellerId] ?? ('#'.$sellerId),
                        'revenue' => $revenue,
                        'count' => $count,
                    ];
                })
                ->sortByDesc(fn (array $row): float => array_sum(array_map('floatval', $row['revenue'])))
                ->take(10)
                ->values();
        }

        // ==================== TRENDS ====================
        $spanDays = (int) $from->diffInDays($to) + 1;
        $useMonthly = $spanDays > 120;
        $fmt = $useMonthly ? '%Y-%m' : '%Y-%m-%d';
        $accSeriesRaw = $accounts()->whereBetween('created_at', [$from, $to])
            ->select(DB::raw("DATE_FORMAT(created_at, '{$fmt}') k"), DB::raw('COUNT(*) c'))->groupBy('k')->pluck('c', 'k');
        // نمودار درآمد برای هر ارز جداگانه ساخته می‌شود؛ ریختن همه‌ی ارزها در یک سری، عدد بی‌معنی می‌سازد.
        $revRows = $invoices()->whereBetween('issued_at', [$from, $to])
            ->select(DB::raw("DATE_FORMAT(issued_at, '{$fmt}') k"), 'currency', DB::raw('SUM(total) s'))
            ->groupBy('k', 'currency')->get();
        $revByCurrency = [];
        foreach ($revRows as $revRow) {
            $revByCurrency[MoneyCurrency::normalize($revRow->currency)->value][$revRow->k] = (float) $revRow->s;
        }
        if ($revByCurrency === []) {
            $revByCurrency[MoneyCurrency::default()->value] = [];
        }

        $labels = [];
        $accSeries = [];
        $revSeries = array_fill_keys(array_keys($revByCurrency), []);
        $cursor = $from->copy();
        $guard = 0;
        while ($cursor->lte($to) && $guard++ < 400) {
            $key = $useMonthly ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
            $labels[] = $useMonthly ? jalali_date($cursor, 'Y/m') : jalali_date($cursor, 'm/d');
            $accSeries[] = (int) ($accSeriesRaw[$key] ?? 0);
            foreach ($revByCurrency as $revCode => $revPoints) {
                $revSeries[$revCode][] = (float) ($revPoints[$key] ?? 0);
            }
            $cursor = $useMonthly ? $cursor->addMonth()->startOfMonth() : $cursor->addDay();
        }

        return [
            'panel' => $panel,
            'scope' => $panel,
            'from' => $from,
            'to' => $to,
            'fromInput' => jalali_date_input($from),
            'toInput' => jalali_date_input($to),
            'kpis' => $kpis,
            'state' => [
                'totalAccounts' => $totalAccounts,
                'activeAccounts' => $activeAccounts,
                'accByStatus' => $byStatus,
                'accByService' => $byService,
                'accByServer' => $byServer,
                'accByPackage' => $byPackage,
                'expiringSoon' => $expiringSoon,
                'lowVolume' => $lowVolume,
                'thresholdDays' => $days,
                'thresholdBytes' => $volBytes,
            ],
            'flags' => [
                'showServers' => $isAdmin,
                'showResellers' => $showResellers,
                'isAdmin' => $isAdmin,
            ],
            'resellers' => [
                'sellersCount' => $sellersCount,
                'walletSellers' => $walletSellers,
                'topSellers' => $topSellers,
                'topSellersRevenue' => $topSellersRevenue,
            ],
            'trend' => [
                'labels' => $labels,
                'accounts' => $accSeries,
                'revenue' => collect($revSeries)
                    ->map(fn (array $values, string $code): array => ['currency' => $code, 'values' => $values])
                    ->values()
                    ->all(),
                'granularity' => $useMonthly ? 'monthly' : 'daily',
            ],
        ];
    }

    /**
     * مجموع یک ستون مبلغ را بر اساس ستون currency گروه‌بندی می‌کند.
     *
     * @return array<string, string> نگاشت کد ارز به مبلغ
     */
    protected function reportSumByCurrency(Builder $query, string $column): array
    {
        $sums = [];

        $rows = $query->select('currency', DB::raw("SUM({$column}) as total_amount"))
            ->groupBy('currency')
            ->get();

        foreach ($rows as $row) {
            $code = MoneyCurrency::normalize($row->currency)->value;
            $sums[$code] = bcadd($sums[$code] ?? '0', (string) ($row->total_amount ?? '0'), 2);
        }

        return $sums;
    }

    /**
     * مبالغ تفکیک‌شده را برای نمایش در یک کارت کنار هم می‌چیند.
     *
     * @param  array<string, string>  $sums
     */
    protected function formatReportMoney(array $sums): string
    {
        if ($sums === []) {
            // وقتی رکوردی وجود ندارد، صفر با ارز پیش‌فرض پنل نمایش داده می‌شود.
            return format_money('0', MoneyCurrency::default());
        }

        return collect($sums)
            ->map(fn (string $amount, string $code): string => format_money($amount, $code))
            ->implode(' + ');
    }
}
