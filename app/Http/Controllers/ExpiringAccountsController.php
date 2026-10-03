<?php

namespace App\Http\Controllers;

use App\Enums\AccountCategory;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Active accounts that expire within the next 1–7 days, for every staff
 * panel: the admin sees all of them (filterable by agent and seller), an
 * agent sees their own and their sellers', a seller only their own.
 */
class ExpiringAccountsController extends Controller
{
    public const MAX_DAYS = 7;

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $base = $this->scopedQuery($user, $filters);

        $accounts = (clone $base)
            ->with(['ownerSeller', 'ownerAgent', 'clientUser', 'server', 'package'])
            ->orderBy('expiry_at')
            ->paginate(25)
            ->withQueryString();

        return view('shared.expiring-accounts.index', [
            'panel' => $this->panel($user),
            'accounts' => $accounts,
            'filters' => $filters,
            'categories' => $this->allowedCategories($user),
            'agents' => $user->role === UserRole::Admin
                ? User::query()->where('role', UserRole::Agent)->orderBy('full_name')->get(['id', 'full_name', 'username'])
                : collect(),
            'sellers' => $this->sellerOptions($user, $filters['agent_id']),
            'summary' => $this->summary($user, $filters),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $query = $this->scopedQuery($user, $this->filters($request))
            ->with(['ownerSeller', 'ownerAgent', 'clientUser', 'server', 'package'])
            ->orderBy('expiry_at');

        return response()->streamDownload(function () use ($query, $user): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values(array_filter([
                __('expiring.col_username'),
                __('expiring.col_client'),
                __('expiring.col_phone'),
                __('expiring.col_service'),
                __('expiring.col_server'),
                __('expiring.col_package'),
                $user->role !== UserRole::Seller ? __('expiring.col_seller') : null,
                $user->role === UserRole::Admin ? __('expiring.col_agent') : null,
                __('expiring.col_used'),
                __('expiring.col_limit'),
                __('expiring.col_expiry'),
                __('expiring.col_auto_renew'),
            ], fn ($v) => $v !== null)));

            $query->chunk(500, function ($accounts) use ($out, $user): void {
                foreach ($accounts as $account) {
                    fputcsv($out, array_values(array_filter([
                        $account->remote_username,
                        $account->clientUser?->full_name ?: $account->clientUser?->username ?: '',
                        $account->clientUser?->phone ?? '',
                        $account->service_type->label(),
                        $account->server?->name ?? '',
                        $account->package?->name ?? '',
                        $user->role !== UserRole::Seller ? ($account->ownerSeller?->full_name ?: $account->ownerSeller?->username ?? '') : null,
                        $user->role === UserRole::Admin ? ($account->ownerAgent?->full_name ?: $account->ownerAgent?->username ?? '') : null,
                        format_data_size((int) $account->data_used_bytes),
                        $account->isUnlimited() ? __('dashboard.unlimited') : format_data_size((int) $account->data_limit_bytes),
                        jalali_date($account->expiry_at, 'Y/m/d H:i'),
                        $account->auto_renew ? '✓' : '',
                    ], fn ($v) => $v !== null)));
                }
            });

            fclose($out);
        }, 'expiring-accounts-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{days: int, category: ?string, agent_id: ?int, seller_id: ?int, search: string, auto_renew: ?string}
     */
    protected function filters(Request $request): array
    {
        $days = (int) $request->query('days', 3);

        return [
            'days' => max(1, min(self::MAX_DAYS, $days)),
            'category' => AccountCategory::tryFrom((string) $request->query('category'))?->value,
            'agent_id' => $request->filled('agent_id') ? (int) $request->query('agent_id') : null,
            'seller_id' => $request->filled('seller_id') ? (int) $request->query('seller_id') : null,
            'search' => trim((string) $request->query('search', '')),
            'auto_renew' => in_array($request->query('auto_renew'), ['1', '0'], true) ? (string) $request->query('auto_renew') : null,
        ];
    }

    /**
     * Everything the viewer may see that expires in the window, with every
     * filter except the period applied.
     */
    protected function scopedQuery(User $user, array $filters, bool $applyWindow = true): Builder
    {
        $categories = array_map(fn (AccountCategory $c): string => $c->value, $this->allowedCategories($user));

        $query = Account::query()
            ->where('status', AccountStatus::Active)
            ->whereNotNull('expiry_at');

        if ($applyWindow) {
            $query->whereBetween('expiry_at', [now(), now()->addDays($filters['days'])]);
        }

        match ($user->role) {
            UserRole::Admin => null,
            UserRole::Seller => $query->where('owner_seller_id', $user->id),
            default => $query->ownedByHierarchy($user),
        };

        // Restricted admins only see the account families they were given.
        $query->where(function (Builder $inner) use ($categories): void {
            foreach ($categories as $category) {
                $inner->orWhere(fn (Builder $q) => $q->inCategory($category));
            }
        });

        if ($filters['category'] !== null && in_array($filters['category'], $categories, true)) {
            $query->inCategory($filters['category']);
        }

        if ($user->role === UserRole::Admin && $filters['agent_id'] !== null) {
            $query->where('owner_agent_id', $filters['agent_id']);
        }

        if ($user->role !== UserRole::Seller && $filters['seller_id'] !== null) {
            $query->where('owner_seller_id', $filters['seller_id']);
        }

        if ($filters['auto_renew'] !== null) {
            $query->where('auto_renew', $filters['auto_renew'] === '1');
        }

        if ($filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('remote_username', 'like', $term)
                    ->orWhereHas('clientUser', fn (Builder $q) => $q
                        ->where('full_name', 'like', $term)
                        ->orWhere('username', 'like', $term)
                        ->orWhere('phone', 'like', $term));
            });
        }

        return $query;
    }

    /**
     * Counts per day of the full 7-day horizon and per service family, under
     * the same viewer scope and filters (not the period).
     *
     * @return array{total: int, within_24h: int, auto_renew: int, days: list<array{label: string, count: int, day: int}>, by_category: array<string, int>}
     */
    protected function summary(User $user, array $filters): array
    {
        $base = $this->scopedQuery($user, array_merge($filters, ['days' => self::MAX_DAYS]));

        $perDay = (clone $base)
            ->selectRaw('DATE(expiry_at) as day_key, COUNT(*) as total')
            ->groupByRaw('DATE(expiry_at)')
            ->pluck('total', 'day_key');

        $days = [];
        for ($i = 0; $i <= self::MAX_DAYS; $i++) {
            $day = now()->addDays($i)->startOfDay();
            $days[] = [
                'label' => $i === 0 ? __('expiring.today') : ($i === 1 ? __('expiring.tomorrow') : jalali_date($day, 'm/d')),
                'count' => (int) ($perDay[$day->toDateString()] ?? 0),
                'day' => $i,
            ];
        }

        $byCategory = [];
        foreach ($this->allowedCategories($user) as $category) {
            $byCategory[$category->value] = (clone $base)->inCategory($category)->count();
        }

        $window = $this->scopedQuery($user, $filters);

        return [
            'total' => (clone $window)->count(),
            'within_24h' => (clone $base)->where('expiry_at', '<=', now()->addDay())->count(),
            'auto_renew' => (clone $window)->where('auto_renew', true)->count(),
            'days' => $days,
            'by_category' => $byCategory,
        ];
    }

    /**
     * @return list<AccountCategory>
     */
    protected function allowedCategories(User $user): array
    {
        return array_values(array_filter(
            AccountCategory::cases(),
            fn (AccountCategory $category): bool => $user->role !== UserRole::Admin || admin_section_allowed('accounts_'.$category->value),
        ));
    }

    /**
     * Sellers the viewer may filter by: all (or one agent's) for the admin,
     * the agent's own sellers for an agent.
     */
    protected function sellerOptions(User $user, ?int $agentId): \Illuminate\Support\Collection
    {
        return match ($user->role) {
            UserRole::Admin => User::query()
                ->where('role', UserRole::Seller)
                ->when($agentId !== null, fn ($q) => $q->where('parent_id', $agentId))
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'username', 'parent_id']),
            UserRole::Agent => User::query()
                ->where('role', UserRole::Seller)
                ->where('parent_id', $user->id)
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'username', 'parent_id']),
            default => collect(),
        };
    }

    protected function panel(User $user): string
    {
        return match ($user->role) {
            UserRole::Admin => 'admin',
            UserRole::Agent => 'agent',
            default => 'seller',
        };
    }

    /**
     * "2 days 5 hours" style time left, used by the view.
     */
    public static function timeLeft(?Carbon $expiry): string
    {
        if ($expiry === null) {
            return '—';
        }

        $minutes = max(0, (int) now()->diffInMinutes($expiry, false));
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = __('expiring.n_days', ['n' => persian_digits($days)]);
        }
        if ($hours > 0) {
            $parts[] = __('expiring.n_hours', ['n' => persian_digits($hours)]);
        }
        if ($days === 0 && $mins > 0) {
            $parts[] = __('expiring.n_minutes', ['n' => persian_digits($mins)]);
        }

        $and = trim((string) __('expiring.and'));

        return $parts === [] ? __('expiring.now') : implode($and === '' ? ' ' : ' '.$and.' ', $parts);
    }
}
