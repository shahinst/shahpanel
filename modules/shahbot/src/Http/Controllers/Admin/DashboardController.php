<?php

namespace Modules\ShahBot\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Modules\ShahBot\Models\BotOrder;
use Modules\ShahBot\Models\BotPayment;
use Modules\ShahBot\Models\BotTicket;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotSettings;

class DashboardController extends Controller
{
    public function index(BotSettings $settings): View
    {
        $paid = fn () => BotOrder::query()->whereIn('type', ['buy', 'renew']);

        $stats = [
            'users' => BotUser::query()->count(),
            'users_today' => BotUser::query()->where('created_at', '>=', today())->count(),
            'customers' => BotUser::query()->whereHas('orders', fn ($q) => $q->whereIn('type', ['buy', 'renew']))->count(),
            'blocked' => BotUser::query()->where('bot_blocked_by_user', true)->count(),
            'sales_today' => (string) $paid()->where('created_at', '>=', today())->sum('amount'),
            'orders_today' => $paid()->where('created_at', '>=', today())->count(),
            'sales_30' => (string) $paid()->where('created_at', '>=', now()->subDays(30))->sum('amount'),
            'orders_30' => $paid()->where('created_at', '>=', now()->subDays(30))->count(),
            'tests' => BotOrder::query()->where('type', 'test')->count(),
            'pending_payments' => BotPayment::query()->where('status', BotPayment::PENDING)->count(),
            'open_tickets' => BotTicket::query()->where('status', BotTicket::OPEN)->count(),
        ];

        // Daily sales of the last 14 days for the chart.
        $daily = $paid()
            ->where('created_at', '>=', today()->subDays(13))
            ->selectRaw('DATE(created_at) as d, SUM(amount) as total, COUNT(*) as n')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('total', 'd');

        $chart = collect(range(13, 0))->map(function (int $ago) use ($daily): array {
            $day = today()->subDays($ago);

            return ['label' => jalali_date($day, 'm/d'), 'total' => (float) ($daily[$day->toDateString()] ?? 0)];
        })->values();

        return view('shahbot::dashboard', [
            'settings' => $settings,
            'stats' => $stats,
            'chart' => $chart,
            'recentOrders' => BotOrder::query()->with(['botUser', 'duration.package', 'account'])->latest('id')->limit(10)->get(),
            'configured' => $settings->isConfigured(),
        ]);
    }

    public function orders(Request $request): View
    {
        $orders = BotOrder::query()
            ->with(['botUser', 'duration.package', 'account'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('shahbot::orders', ['orders' => $orders]);
    }
}
