<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AccountCategory;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

trait ListsAccountsByCategory
{
    use ProvidesStaffAccountCreateModal;

    public function indexWireguard(Request $request): View
    {
        return $this->indexByCategory($request, AccountCategory::Wireguard);
    }

    public function indexPpp(Request $request): View
    {
        return $this->indexByCategory($request, AccountCategory::Ppp);
    }

    public function indexV2ray(Request $request): View
    {
        return $this->indexByCategory($request, AccountCategory::V2ray);
    }

    public function indexAnyconnect(Request $request): View
    {
        return $this->indexByCategory($request, AccountCategory::Anyconnect);
    }

    /**
     * Accounts that need attention: expiring within the configured number of days,
     * OR with less than the configured volume left. Scoped to the viewer (admin sees
     * all, agent their hierarchy, seller their own) — same rules as the normal lists.
     */
    public function indexExpiring(Request $request): View
    {
        $this->authorize('viewAny', Account::class);

        $days = \App\Support\ExpiringAccountThresholds::days();
        $volumeBytes = \App\Support\ExpiringAccountThresholds::volumeBytes();
        $until = now()->addDays($days);

        $accounts = $this->accountsQueryForViewer($request)
            ->where(function (Builder $query) use ($until, $volumeBytes): void {
                $query->where(function (Builder $inner) use ($until): void {
                    $inner->whereNotNull('expiry_at')
                        ->where('expiry_at', '>=', now())
                        ->where('expiry_at', '<=', $until);
                })->orWhere(function (Builder $inner) use ($volumeBytes): void {
                    $inner->whereNotNull('data_limit_bytes')
                        ->whereRaw('(data_limit_bytes - COALESCE(data_used_bytes, 0)) < ?', [$volumeBytes]);
                });
            })
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $this->applyAccountSearchFilter($query, $request->string('search')->toString());
            })
            ->when($request->filled('service_type'), function (Builder $query) use ($request): void {
                $type = \App\Enums\ServiceType::tryFrom($request->string('service_type')->toString());
                if ($type !== null) {
                    $query->where('service_type', $type);
                }
            })
            ->when($request->filled('reason'), function (Builder $query) use ($request, $until, $volumeBytes): void {
                if ($request->string('reason')->toString() === 'expiry') {
                    $query->whereNotNull('expiry_at')->where('expiry_at', '>=', now())->where('expiry_at', '<=', $until);
                } elseif ($request->string('reason')->toString() === 'volume') {
                    $query->whereNotNull('data_limit_bytes')
                        ->whereRaw('(data_limit_bytes - COALESCE(data_used_bytes, 0)) < ?', [$volumeBytes]);
                }
            })
            ->orderByRaw('expiry_at IS NULL, expiry_at ASC')
            ->paginate(20)
            ->withQueryString();

        return view('shared.accounts.expiring', [
            'accounts' => $accounts,
            'prefix' => $this->accountRoutePrefix(),
            'showOwnerColumn' => $this->shouldShowOwnerColumn(),
            'thresholdDays' => $days,
            'thresholdBytes' => $volumeBytes,
            'serviceTypeOptions' => \App\Enums\ServiceType::cases(),
        ]);
    }

    protected function indexByCategory(Request $request, AccountCategory $category): View
    {
        $this->authorize('viewAny', Account::class);

        $accounts = $this->accountsQueryForViewer($request)
            ->inCategory($category)
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $this->applyAccountSearchFilter($query, $request->string('search')->toString());
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('owner_role'), function (Builder $query) use ($request): void {
                $role = UserRole::tryFrom($request->string('owner_role')->toString());

                if ($role !== null && in_array($role, [UserRole::Agent, UserRole::Seller], true)) {
                    $query->whereHas('ownerSeller', fn (Builder $ownerQuery) => $ownerQuery->where('role', $role));
                }
            })
            ->when($request->filled('owner_id'), function (Builder $query) use ($request): void {
                $ownerId = (int) $request->input('owner_id');

                if ($this->isAllowedOwnerFilter($request, $ownerId)) {
                    $query->where('owner_seller_id', $ownerId);
                }
            })
            ->when($request->filled('server_id'), function (Builder $query) use ($request, $category): void {
                $serverId = (int) $request->input('server_id');

                if ($serverId > 0 && $this->isAllowedServerFilter($serverId, $category)) {
                    $query->where('server_id', $serverId);
                }
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('shared.accounts.index', array_merge(
            [
                'accounts' => $accounts,
                'category' => $category,
                'prefix' => $this->accountRoutePrefix(),
                'showOwnerColumn' => $this->shouldShowOwnerColumn(),
                'showOwnerFilter' => $this->shouldShowOwnerFilter(),
                'ownerFilterOptions' => $this->ownerFilterOptions($request),
                'serverFilterOptions' => $this->serverFilterOptions($category),
            ],
            $this->staffAccountCreateModalData($request),
        ));
    }

    /**
     * @return \Illuminate\Support\Collection<int, Server>
     */
    protected function serverFilterOptions(AccountCategory $category): \Illuminate\Support\Collection
    {
        return Server::query()
            ->active()
            ->forAccountCategory($category)
            ->where(function (Builder $query) use ($category): void {
                $query->where('show_in_account_filters', true)
                    ->orWhereHas('accounts', fn (Builder $accountQuery) => $accountQuery
                        ->whereIn('service_type', $category->serviceTypeValues()));
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    protected function isAllowedServerFilter(int $serverId, AccountCategory $category): bool
    {
        return Server::query()
            ->whereKey($serverId)
            ->active()
            ->forAccountCategory($category)
            ->where(function (Builder $query) use ($category): void {
                $query->where('show_in_account_filters', true)
                    ->orWhereHas('accounts', fn (Builder $accountQuery) => $accountQuery
                        ->whereIn('service_type', $category->serviceTypeValues()));
            })
            ->exists();
    }

    /**
     * @return array{agents?: \Illuminate\Support\Collection<int, User>, sellers: \Illuminate\Support\Collection<int, User>}
     */
    protected function ownerFilterOptions(Request $request): array
    {
        $user = $request->user();

        if ($user->role === UserRole::Admin) {
            return [
                'agents' => User::query()
                    ->role(UserRole::Agent)
                    ->orderBy('full_name')
                    ->get(['id', 'full_name', 'username', 'role']),
                'sellers' => User::query()
                    ->role(UserRole::Seller)
                    ->orderBy('full_name')
                    ->get(['id', 'full_name', 'username', 'role']),
            ];
        }

        if ($user->role === UserRole::Agent) {
            return [
                'sellers' => User::query()
                    ->ownedByHierarchy($user, 'id')
                    ->role(UserRole::Seller)
                    ->orderBy('full_name')
                    ->get(['id', 'full_name', 'username', 'role']),
            ];
        }

        return ['sellers' => collect()];
    }

    protected function isAllowedOwnerFilter(Request $request, int $ownerId): bool
    {
        if ($ownerId <= 0) {
            return false;
        }

        $user = $request->user();

        if ($user->role === UserRole::Admin) {
            return User::query()
                ->whereKey($ownerId)
                ->whereIn('role', [UserRole::Agent, UserRole::Seller])
                ->exists();
        }

        if ($user->role === UserRole::Agent) {
            return in_array($ownerId, User::subtreeUserIds($user), true);
        }

        return false;
    }

    protected function shouldShowOwnerFilter(): bool
    {
        $user = auth()->user();

        return in_array($user?->role, [UserRole::Admin, UserRole::Agent], true);
    }

    protected function accountsQueryForViewer(Request $request): Builder
    {
        $query = Account::query()
            ->with(['ownerSeller', 'ownerAgent', 'package', 'packageDuration', 'server']);

        $user = $request->user();

        if ($user->role === UserRole::Admin) {
            return $query;
        }

        if ($user->role === UserRole::Agent) {
            return $query->ownedByHierarchy($user);
        }

        return $query->where('owner_seller_id', $user->id);
    }

    protected function shouldShowOwnerColumn(): bool
    {
        $user = auth()->user();

        return in_array($user?->role, [UserRole::Admin, UserRole::Agent], true);
    }

    protected function applyAccountSearchFilter(Builder $query, string $search): void
    {
        $term = trim($search);

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';

        $query->where(function (Builder $inner) use ($like, $term): void {
            $inner->where('remote_username', 'like', $like)
                ->orWhere('display_label', 'like', $like)
                ->orWhere('client_email', 'like', $like)
                ->orWhere('wireguard_address', 'like', $like);

            // WireGuard IPs are often stored as 10.8.0.5/32 — match the host part too.
            if (str_contains($term, '/')) {
                $host = explode('/', $term, 2)[0];
                if ($host !== '' && $host !== $term) {
                    $inner->orWhere('wireguard_address', 'like', '%'.$host.'%');
                }
            }
        });
    }

    protected function accountsListRoute(?Account $account = null): string
    {
        $prefix = $this->accountRoutePrefix();
        $category = $account?->service_type?->accountCategory() ?? AccountCategory::Wireguard;

        return route("{$prefix}.accounts.{$category->value}");
    }

    protected function safeAccountsListRoute(?Account $account = null): string
    {
        try {
            return $this->accountsListRoute($account);
        } catch (\Throwable) {
            $prefix = $this->accountRoutePrefix();

            foreach ([AccountCategory::Ppp, AccountCategory::Wireguard, AccountCategory::V2ray, AccountCategory::Anyconnect] as $category) {
                $routeName = "{$prefix}.accounts.{$category->value}";

                if (\Illuminate\Support\Facades\Route::has($routeName)) {
                    return route($routeName);
                }
            }

            return route("{$prefix}.dashboard");
        }
    }
}
