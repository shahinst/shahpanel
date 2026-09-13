<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AccountStatus;
use App\Enums\InvoiceType;
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
        $revTotal = (string) $invoices()->whereBetween('issued_at', [$from, $to])->sum('total');
        $revNew = (string) $invoices()->whereBetween('issued_at', [$from, $to])->where('type', InvoiceType::NewAccount)->sum('total');
        $revRenew = (string) $invoices()->whereBetween('issued_at', [$from, $to])->where('type', InvoiceType::Renewal)->sum('total');
        $cntRenew = $invoices()->whereBetween('issued_at', [$from, $to])->where('type', InvoiceType::Renewal)->count();
        $newAccounts = $accounts()->whereBetween('created_at', [$from, $to])->count();
        $refundsCount = $accounts()->whereBetween('refunded_at', [$from, $to])->count();

        // margin/commission earned by an agent in the period
        $agentEarned = '0';
        if ($isAgent) {
            $agentEarned = (string) Transaction::query()
                ->where('user_id', $viewer->id)
                ->whereIn('type', [TransactionType::Margin, TransactionType::Commission])
                ->whereBetween('created_at', [$from, $to])->sum('amount');
        }
        $adminRevenue = '0';
        $agentMarginAll = '0';
        if ($isAdmin) {
            $adminRevenue = (string) Transaction::query()->where('type', TransactionType::Revenue)
                ->whereBetween('created_at', [$from, $to])->sum('amount');
            $agentMarginAll = (string) Transaction::query()->whereIn('type', [TransactionType::Margin, TransactionType::Commission])
                ->whereBetween('created_at', [$from, $to])->sum('amount');
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

        $myWallet = (string) (Wallet::query()->where('user_id', $viewer->id)->value('balance') ?? '0');

        // ---- role-aware KPI cards ----
        $revLabel = $isAdmin ? 'درآمد کل (فاکتورها)' : ($isAgent ? 'گردش مالی مجموعه' : 'مجموع خرید شما');
        $kpis = [
            ['title' => $revLabel, 'value' => format_toman($revTotal), 'icon' => 'bx-wallet', 'color' => 'success',
                'hint' => 'جدید: '.format_toman($revNew).' | تمدید: '.format_toman($revRenew)],
            ['title' => 'اکانت‌های جدید', 'value' => persian_digits($newAccounts), 'icon' => 'bx-plus-circle', 'color' => 'primary',
                'hint' => persian_digits($cntRenew).' تمدید در این بازه'],
            ['title' => 'برگشت از خرید', 'value' => persian_digits($refundsCount), 'icon' => 'bx-undo', 'color' => 'danger', 'hint' => null],
        ];
        if ($isAdmin) {
            $kpis[] = ['title' => 'درآمد ادمین', 'value' => format_toman($adminRevenue), 'icon' => 'bx-trending-up', 'color' => 'success',
                'hint' => 'سود نماینده‌ها: '.format_toman($agentMarginAll)];
            $kpis[] = ['title' => 'نمایندگان جدید', 'value' => persian_digits($newAgents), 'icon' => 'bx-user-pin', 'color' => 'primary', 'hint' => null];
            $kpis[] = ['title' => 'فروشندگان جدید', 'value' => persian_digits($newSellers), 'icon' => 'bx-user', 'color' => 'primary', 'hint' => null];
            $kpis[] = ['title' => 'مشتریان جدید', 'value' => persian_digits($newClients), 'icon' => 'bx-group', 'color' => 'primary', 'hint' => null];
        } elseif ($isAgent) {
            $kpis[] = ['title' => 'سود شما (بازه)', 'value' => format_toman($agentEarned), 'icon' => 'bx-trending-up', 'color' => 'success', 'hint' => null];
            $kpis[] = ['title' => 'فروشندگان جدید', 'value' => persian_digits($newSellers), 'icon' => 'bx-user', 'color' => 'primary', 'hint' => null];
            $kpis[] = ['title' => 'مشتریان جدید', 'value' => persian_digits($newClients), 'icon' => 'bx-group', 'color' => 'primary', 'hint' => null];
            $kpis[] = ['title' => 'موجودی کیف‌پول شما', 'value' => format_toman($myWallet), 'icon' => 'bx-credit-card', 'color' => 'warning', 'hint' => null];
        } else { // seller
            $kpis[] = ['title' => 'مشتریان جدید', 'value' => persian_digits($newClients), 'icon' => 'bx-group', 'color' => 'primary', 'hint' => null];
            $kpis[] = ['title' => 'موجودی کیف‌پول شما', 'value' => format_toman($myWallet), 'icon' => 'bx-credit-card', 'color' => 'warning', 'hint' => null];
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
        $walletSellers = '0';
        if ($showResellers) {
            $sellersCount = $isAdmin ? User::query()->role(UserRole::Seller)->count() : $sellerIds->count();
            $walletSellers = (string) Wallet::query()->whereIn('user_id', $sellerIds->all() ?: [0])->sum('balance');

            $topSellers = $accounts()->whereNotNull('owner_seller_id')
                ->select('owner_seller_id', DB::raw('COUNT(*) c'), DB::raw("SUM(status='active') a"))
                ->groupBy('owner_seller_id')->orderByDesc('c')->limit(10)->get()
                ->map(fn ($r) => ['name' => $userNames[$r->owner_seller_id] ?? ('#'.$r->owner_seller_id), 'total' => (int) $r->c, 'active' => (int) $r->a]);

            $topSellersRevenue = $invoices()->whereBetween('issued_at', [$from, $to])->whereNotNull('seller_user_id')
                ->select('seller_user_id', DB::raw('SUM(total) s'), DB::raw('COUNT(*) c'))
                ->groupBy('seller_user_id')->orderByDesc('s')->limit(10)->get()
                ->map(fn ($r) => ['name' => $userNames[$r->seller_user_id] ?? ('#'.$r->seller_user_id), 'revenue' => (string) $r->s, 'count' => (int) $r->c]);
        }

        // ==================== TRENDS ====================
        $spanDays = (int) $from->diffInDays($to) + 1;
        $useMonthly = $spanDays > 120;
        $fmt = $useMonthly ? '%Y-%m' : '%Y-%m-%d';
        $accSeriesRaw = $accounts()->whereBetween('created_at', [$from, $to])
            ->select(DB::raw("DATE_FORMAT(created_at, '{$fmt}') k"), DB::raw('COUNT(*) c'))->groupBy('k')->pluck('c', 'k');
        $revSeriesRaw = $invoices()->whereBetween('issued_at', [$from, $to])
            ->select(DB::raw("DATE_FORMAT(issued_at, '{$fmt}') k"), DB::raw('SUM(total) s'))->groupBy('k')->pluck('s', 'k');

        $labels = [];
        $accSeries = [];
        $revSeries = [];
        $cursor = $from->copy();
        $guard = 0;
        while ($cursor->lte($to) && $guard++ < 400) {
            $key = $useMonthly ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
            $labels[] = $useMonthly ? jalali_date($cursor, 'Y/m') : jalali_date($cursor, 'm/d');
            $accSeries[] = (int) ($accSeriesRaw[$key] ?? 0);
            $revSeries[] = (float) ($revSeriesRaw[$key] ?? 0);
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
                'revenue' => $revSeries,
                'granularity' => $useMonthly ? 'monthly' : 'daily',
            ],
        ];
    }
}
