<?php

namespace App\Http\Controllers\Agent;

use App\Enums\MoneyCurrency;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\ManagesUserPackageAssignment;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserCurrencyService;
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
        protected UserCurrencyService $userCurrencyService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $sellers = User::query()
            ->ownedByHierarchy($request->user(), 'id')
            ->role(UserRole::Seller)
            ->with('wallets')
            ->whereIn('status', array_column(UserStatus::cases(), 'value'))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($inner) use ($search): void {
                    $inner->where('username', 'like', "%{$search}%")
                        ->orWhere('full_name', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('agent.sellers.index', compact('sellers'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        return view('agent.sellers.create', array_merge(
            $this->packageAssignmentFormData($request->user()),
            ['parents' => collect([$request->user()])]
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        try {
            $validated = $request->validate([
                'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'full_name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:20'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'status' => ['required', Rule::enum(UserStatus::class)],
                'settlement_currency' => ['required', 'string', Rule::enum(MoneyCurrency::class)],
                ...$this->packageAssignmentValidationRules(),
            ]);

            $seller = User::query()->create([
                ...collect($validated)->except(['password', 'package_ids', 'settlement_currency'])->all(),
                'role' => UserRole::Seller,
                'parent_id' => $request->user()->id,
                'password' => Hash::make($validated['password']),
            ]);

            $this->userCurrencyService->syncSellerSettlement(
                $seller,
                $validated['settlement_currency'] ?? MoneyCurrency::IRT->value,
                $request->user()
            );
            $seller->refresh();
            $this->syncUserPackagesFromRequest($request, $seller, $request->user());
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('agent.sellers.index')
            ->with('success', __('app.saved'));
    }

    public function edit(Request $request, User $seller): View
    {
        $this->authorize('update', $seller);

        return view('agent.sellers.edit', array_merge(
            compact('seller'),
            $this->packageAssignmentFormData($request->user(), $seller),
            ['parents' => collect([$request->user()])]
        ));
    }

    public function update(Request $request, User $seller): RedirectResponse
    {
        $this->authorize('update', $seller);

        try {
            $validated = $request->validate([
                'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($seller->id)],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($seller->id)],
                'full_name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:20'],
                'password' => ['nullable', 'string', 'min:8', 'confirmed'],
                'status' => ['required', Rule::enum(UserStatus::class)],
                'settlement_currency' => ['required', 'string', Rule::enum(MoneyCurrency::class)],
                ...$this->packageAssignmentValidationRules(),
            ]);

            if (! empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }

            $settlement = $validated['settlement_currency'] ?? MoneyCurrency::IRT->value;
            unset($validated['settlement_currency']);

            $seller->update(collect($validated)->except('package_ids')->all());
            $this->userCurrencyService->syncSellerSettlement($seller->fresh(), $settlement, $request->user());
            $seller->refresh();
            $this->syncUserPackagesFromRequest($request, $seller, $request->user());
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('agent.sellers.index')
            ->with('success', __('app.saved'));
    }
}
