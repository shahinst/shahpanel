<?php

namespace App\Http\Controllers\Admin;

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

class UserController extends Controller
{
    use ManagesUserPackageAssignment;

    public function __construct(
        protected WalletService $walletService,
        protected UserCurrencyService $userCurrencyService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->role(UserRole::Agent)
            ->with('wallets')
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

        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.create', $this->packageAssignmentFormData(request()->user()));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'daily_server_change_limit' => ['nullable', 'integer', 'min:0', 'max:100'],
            'enabled_currencies' => ['nullable', 'array', 'min:1'],
            'enabled_currencies.*' => ['string', Rule::enum(MoneyCurrency::class)],
            ...$this->packageAssignmentValidationRules(),
        ]);

        $user = User::query()->create([
            ...collect($validated)->except(['daily_server_change_limit', 'password', 'enabled_currencies'])->all(),
            'role' => UserRole::Agent,
            'parent_id' => $request->user()->id,
            'password' => Hash::make($validated['password']),
            'daily_server_change_limit' => $validated['daily_server_change_limit']
                ?? (int) \App\Models\Setting::getValue('default_agent_daily_server_changes', 5),
            'settlement_currency' => MoneyCurrency::IRT->value,
        ]);

        $this->userCurrencyService->syncAgentCurrencies($user, $validated['enabled_currencies'] ?? [MoneyCurrency::IRT->value]);
        $this->syncUserPackagesFromRequest($request, $user, $request->user());

        return redirect()
            ->route('admin.users.index')
            ->with('success', __('app.saved'));
    }

    public function edit(Request $request, User $user): View
    {
        $this->authorize('update', $user);

        $wallet = $this->walletService->getOrCreateWallet($user);
        $user->load('wallets');

        return view('admin.users.edit', array_merge(
            compact('user', 'wallet'),
            $this->packageAssignmentFormData($request->user(), $user)
        ));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'daily_server_change_limit' => ['nullable', 'integer', 'min:0', 'max:100'],
            'enabled_currencies' => ['nullable', 'array', 'min:1'],
            'enabled_currencies.*' => ['string', Rule::enum(MoneyCurrency::class)],
            ...$this->packageAssignmentValidationRules(),
        ]);

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $enabled = $validated['enabled_currencies'] ?? null;
        unset($validated['enabled_currencies']);

        $user->update($validated);
        $this->userCurrencyService->syncAgentCurrencies($user->fresh(), $enabled);
        $this->syncUserPackagesFromRequest($request, $user, $request->user());

        return redirect()
            ->route('admin.users.index')
            ->with('success', __('app.saved'));
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', __('app.deleted'));
    }
}
