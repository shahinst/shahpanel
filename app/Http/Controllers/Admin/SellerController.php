<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MoneyCurrency;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\ManagesUserPackageAssignment;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserCurrencyService;
use App\Services\UserHierarchyService;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SellerController extends Controller
{
    use ManagesUserPackageAssignment;

    public function __construct(
        protected WalletService $walletService,
        protected UserHierarchyService $userHierarchyService,
        protected UserCurrencyService $userCurrencyService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $sellers = User::query()
            ->role(UserRole::Seller)
            ->with(['parent', 'wallets'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($inner) use ($search): void {
                    $inner->where('username', 'like', "%{$search}%")
                        ->orWhere('full_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.sellers.index', compact('sellers'));
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        $parents = $this->parentOptions();
        $defaultParent = $parents->first();

        return view('admin.sellers.create', array_merge(
            compact('parents'),
            $this->packageAssignmentFormData(auth()->user(), null, $defaultParent)
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        try {
            $validated = $request->validate([
                'parent_id' => ['required', 'exists:users,id'],
                'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'full_name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:20'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'status' => ['required', Rule::enum(UserStatus::class)],
                'settlement_currency' => ['required', 'string', Rule::enum(MoneyCurrency::class)],
                ...$this->packageAssignmentValidationRules(),
            ]);

            $parent = $this->userHierarchyService->validateSellerParent((int) $validated['parent_id']);

            $user = User::query()->create([
                ...collect($validated)->except(['parent_id', 'password', 'package_ids', 'settlement_currency'])->all(),
                'role' => UserRole::Seller,
                'parent_id' => $parent->id,
                'password' => Hash::make($validated['password']),
            ]);

            $this->userCurrencyService->syncSellerSettlement(
                $user,
                $validated['settlement_currency'] ?? MoneyCurrency::IRT->value,
                $parent
            );
            $user->refresh();
            $this->syncUserPackagesFromRequest($request, $user, $request->user());
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.sellers.index')
            ->with('success', __('app.saved'));
    }

    public function edit(Request $request, User $seller): View
    {
        $this->authorize('update', $seller);
        abort_unless($seller->role === UserRole::Seller, 404);

        $parents = $this->parentOptions();
        $wallet = $this->walletService->getOrCreateWallet(
            $seller,
            $seller->settlementMoneyCurrency()
        );
        $seller->load('wallets');

        return view('admin.sellers.edit', array_merge(
            compact('seller', 'parents', 'wallet'),
            $this->packageAssignmentFormData($request->user(), $seller)
        ));
    }

    public function update(Request $request, User $seller): RedirectResponse
    {
        $this->authorize('update', $seller);
        abort_unless($seller->role === UserRole::Seller, 404);

        try {
            $validated = $request->validate([
                'parent_id' => ['required', 'exists:users,id'],
                'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($seller->id)],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($seller->id)],
                'full_name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:20'],
                'password' => ['nullable', 'string', 'min:8', 'confirmed'],
                'status' => ['required', Rule::enum(UserStatus::class)],
                'settlement_currency' => ['required', 'string', Rule::enum(MoneyCurrency::class)],
                ...$this->packageAssignmentValidationRules(),
            ]);

            $parent = $this->userHierarchyService->validateSellerParent((int) $validated['parent_id']);

            if (! empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }

            $settlement = $validated['settlement_currency'] ?? MoneyCurrency::IRT->value;
            unset($validated['settlement_currency']);

            $seller->update([
                ...collect($validated)->except(['parent_id', 'package_ids'])->all(),
                'parent_id' => $parent->id,
            ]);
            $this->userCurrencyService->syncSellerSettlement($seller->fresh(), $settlement, $parent);
            $seller->refresh();
            $this->syncUserPackagesFromRequest($request, $seller, $request->user());
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.sellers.index')
            ->with('success', __('app.saved'));
    }

    public function destroy(User $seller): RedirectResponse
    {
        $this->authorize('delete', $seller);
        abort_unless($seller->role === UserRole::Seller, 404);

        $seller->delete();

        return redirect()
            ->route('admin.sellers.index')
            ->with('success', __('app.deleted'));
    }

    public function promote(User $seller): RedirectResponse
    {
        $this->authorize('update', $seller);
        abort_unless($seller->role === UserRole::Seller, 404);

        try {
            $this->userHierarchyService->promoteSellerToAgent($seller);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.users.index')
            ->with('success', __('sellers.promoted'));
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    protected function parentOptions()
    {
        return User::query()
            ->role(UserRole::Agent)
            ->orderBy('full_name')
            ->get();
    }
}
