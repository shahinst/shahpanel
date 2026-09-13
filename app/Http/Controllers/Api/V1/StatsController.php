<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\DashboardStatsService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class StatsController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        protected DashboardStatsService $stats,
        protected WalletService $wallets,
    ) {}

    /**
     * Headline numbers for a bot's home screen. Panel stats come from the same
     * service the dashboard uses; the counts below are scoped to the caller's
     * own hierarchy.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $panel = [];

        try {
            $panel = $user->role === UserRole::Agent
                ? $this->stats->safeForAgent($user)
                : $this->stats->safeForSeller($user);
        } catch (Throwable $e) {
            report($e);
            $panel = [];
        }

        $scoped = Account::query()->ownedByHierarchy($user);

        $wallet = $this->wallets->getOrCreateWallet($user);

        return $this->ok([
            'role' => $user->role->value,
            'wallet' => [
                'balance' => (string) $wallet->balance,
                'currency' => $wallet->currency,
            ],
            'accounts' => [
                'total' => (clone $scoped)->count(),
                'active' => (clone $scoped)->where('status', 'active')->count(),
                'disabled' => (clone $scoped)->where('status', 'disabled')->count(),
                'expired' => (clone $scoped)->where('status', 'expired')->count(),
                'exhausted' => (clone $scoped)->where('status', 'exhausted')->count(),
                'expiring_7d' => (clone $scoped)
                    ->whereNotNull('expiry_at')
                    ->whereBetween('expiry_at', [now(), now()->addDays(7)])
                    ->count(),
                'created_today' => (clone $scoped)->whereDate('created_at', today())->count(),
                'created_this_month' => (clone $scoped)
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->count(),
            ],
            'panel' => $panel,
        ]);
    }
}
