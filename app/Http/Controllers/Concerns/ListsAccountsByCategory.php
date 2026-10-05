<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AccountCategory;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    protected function indexByCategory(Request $request, AccountCategory $category): View
    {
        $this->authorize('viewAny', Account::class);

        $accounts = $this->filteredCategoryQuery($request, $category)
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
                // Deleting accounts is the admin's alone (AccountPolicy::delete),
                // so agents and sellers never get the checkboxes either.
                'canBulkDelete' => $request->user()?->role === UserRole::Admin,
                'ownerFilterOptions' => $this->ownerFilterOptions($request),
                'serverFilterOptions' => $this->serverFilterOptions($category),
            ],
            $this->staffAccountCreateModalData($request),
        ));
    }

    /**
     * @return Collection<int, Server>
     */
    /**
     * The list a staff member sees, with every filter of the page applied.
     * Shared by the page and its export so a download can never hold an
     * account the page itself would not show.
     */
    protected function filteredCategoryQuery(Request $request, AccountCategory $category): Builder
    {
        return $this->accountsQueryForViewer($request)
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
            });
    }

    public function exportCategory(Request $request, string $category): StreamedResponse
    {
        $this->authorize('viewAny', Account::class);

        $category = AccountCategory::tryFrom($category) ?? abort(404);
        $viewer = $request->user();
        $showOwner = $viewer->role !== UserRole::Seller;
        $query = $this->filteredCategoryQuery($request, $category)
            ->with(['ownerSeller', 'clientUser', 'server', 'package'])
            ->latest();

        return response()->streamDownload(function () use ($query, $showOwner): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values(array_filter([
                __('expiring.col_username'),
                __('expiring.col_client'),
                __('expiring.col_phone'),
                __('expiring.col_service'),
                __('expiring.col_server'),
                __('expiring.col_package'),
                $showOwner ? __('expiring.col_seller') : null,
                __('accounts.status'),
                __('expiring.col_used'),
                __('expiring.col_limit'),
                __('expiring.col_expiry'),
            ], fn ($v) => $v !== null)));

            $query->chunk(500, function ($accounts) use ($out, $showOwner): void {
                foreach ($accounts as $account) {
                    fputcsv($out, array_values(array_filter([
                        $account->remote_username,
                        $account->clientUser?->full_name ?: $account->clientUser?->username ?: '',
                        $account->clientUser?->phone ?? '',
                        $account->service_type->label(),
                        $account->server?->name ?? '',
                        $account->package?->name ?? '',
                        $showOwner ? ($account->ownerSeller?->full_name ?: $account->ownerSeller?->username ?? '') : null,
                        $account->status->value,
                        format_data_size((int) $account->data_used_bytes),
                        $account->isUnlimited() ? __('dashboard.unlimited') : format_data_size((int) $account->data_limit_bytes),
                        $account->expiry_at ? jalali_date($account->expiry_at, 'Y/m/d H:i') : '',
                    ], fn ($v) => $v !== null)));
                }
            });

            fclose($out);
        }, 'accounts-'.$category->value.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function serverFilterOptions(AccountCategory $category): Collection
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
     * @return array{agents?: Collection<int, User>, sellers: Collection<int, User>}
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

                if (Route::has($routeName)) {
                    return route($routeName);
                }
            }

            return route("{$prefix}.dashboard");
        }
    }
}

