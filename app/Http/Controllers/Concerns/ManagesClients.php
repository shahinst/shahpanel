<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\PaymentRequestStatus;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\PaymentRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClientAccountLinkService;
use App\Services\ClientAccountReassignmentService;
use App\Services\EndUserService;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

trait ManagesClients
{
    abstract protected function clientsPanel(): string;

    public function index(Request $request, EndUserService $endUserService): View
    {
        $this->authorize('viewAny', User::class);

        $clients = $endUserService->clientsQueryForViewer($request->user())
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('username', 'like', $term)
                        ->orWhere('full_name', 'like', $term);
                });
            })
            ->with(['parent'])
            ->withCount('clientAccounts')
            ->paginate(20)
            ->withQueryString();

        return view('shared.clients.index', [
            'clients' => $clients,
            'panel' => $this->clientsPanel(),
        ]);
    }

    public function create(Request $request, EndUserService $endUserService): View
    {
        $this->authorize('create', User::class);

        $clientOwners = $endUserService->clientOwnersForActor($request->user());
        $showOwnerSelect = $request->user()->role !== UserRole::Seller;

        return view('shared.clients.create', [
            'clientOwners' => $clientOwners,
            'showOwnerSelect' => $showOwnerSelect,
            'panel' => $this->clientsPanel(),
        ]);
    }

    public function store(Request $request, EndUserService $endUserService): RedirectResponse
    {
        $this->authorize('create', User::class);

        $rules = [
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6'],
            'full_name' => ['nullable', 'string', 'max:255'],
        ];

        if ($request->user()->role !== UserRole::Seller) {
            $rules['owner_id'] = ['required', 'integer', 'exists:users,id'];
        }

        $validated = $request->validate($rules);

        try {
            $owner = $endUserService->resolveClientOwnerForActor(
                $request->user(),
                isset($validated['owner_id']) ? (int) $validated['owner_id'] : null
            );

            $client = $endUserService->createStandaloneClient(
                $owner,
                (string) $validated['username'],
                (string) $validated['password'],
                $validated['full_name'] ?? null
            );
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route($this->clientsPanel().'.clients.show', $client)
            ->with('success', __('clients.client_created'));
    }

    public function show(
        Request $request,
        User $client,
        EndUserService $endUserService,
        WalletService $walletService,
        ClientAccountLinkService $linkService,
    ): View {
        abort_unless($client->role === UserRole::Client, 404);
        $this->authorize('view', $client);

        $client->load(['parent', 'wallet']);
        $wallet = $walletService->getOrCreateWallet($client);

        $accounts = Account::query()
            ->forClient($client)
            ->with(['package', 'packageDuration', 'server', 'ownerSeller'])
            ->latest('created_at')
            ->get();

        $unassignedAccounts = collect();
        try {
            $unassignedAccounts = $linkService->unassignedAccountsForClient($client, $request->user());
        } catch (\Throwable) {
            $unassignedAccounts = collect();
        }

        $paymentRequests = PaymentRequest::query()
            ->where('requester_user_id', $client->id)
            ->latest('created_at')
            ->limit(10)
            ->get();

        $pendingChargeCount = PaymentRequest::query()
            ->where('requester_user_id', $client->id)
            ->where('status', PaymentRequestStatus::Pending)
            ->count();

        $recentTransactions = Transaction::query()
            ->where('user_id', $client->id)
            ->latest('created_at')
            ->limit(8)
            ->get();

        try {
            $portal = $endUserService->portalOwnerContext($client);
            $owner = $portal['owner'];
            $ownerRoleLabel = $portal['owner_role_label'];
            $paymentCards = $portal['payment_cards'];
        } catch (\Throwable) {
            $owner = $client->parent ?? $client;
            $ownerRoleLabel = $owner->role->label();
            $paymentCards = collect();
        }
        $clientLoginUrl = client_portal_login_url();
        $canImpersonate = $request->user()->can('impersonate', $client);

        $paymentCardEditRoute = null;
        $viewer = $request->user();

        if ((int) $viewer->id === (int) $owner->id) {
            $paymentCardEditRoute = match ($viewer->role) {
                UserRole::Agent => 'agent.client-payment-card.edit',
                UserRole::Seller => 'seller.client-payment-card.edit',
                UserRole::Admin => 'admin.client-payment-card.edit',
                default => null,
            };
        }

        $transferTargets = collect();
        if (in_array($request->user()->role, [UserRole::Agent, UserRole::Admin], true)) {
            $transferTargets = $endUserService->clientsQueryForViewer($request->user())
                ->where('id', '!=', $client->id)
                ->where('parent_id', $client->parent_id)
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'username']);
        }

        return view('shared.clients.show', [
            'client' => $client,
            'wallet' => $wallet,
            'accounts' => $accounts,
            'unassignedAccounts' => $unassignedAccounts,
            'paymentRequests' => $paymentRequests,
            'pendingChargeCount' => $pendingChargeCount,
            'recentTransactions' => $recentTransactions,
            'owner' => $owner,
            'ownerRoleLabel' => $ownerRoleLabel,
            'paymentCards' => $paymentCards,
            'clientLoginUrl' => $clientLoginUrl,
            'canImpersonate' => $canImpersonate,
            'paymentCardEditRoute' => $paymentCardEditRoute,
            'transferTargets' => $transferTargets,
            'panel' => $this->clientsPanel(),
            'canReassignAccounts' => in_array($request->user()->role, [UserRole::Agent, UserRole::Admin], true),
            'canAssignAccounts' => true,
        ]);
    }

    public function assignAccount(
        Request $request,
        User $client,
        Account $account,
        ClientAccountLinkService $linkService,
    ): RedirectResponse {
        abort_unless($client->role === UserRole::Client, 404);
        $this->authorize('view', $client);
        $this->authorize('update', $account);

        try {
            $linkService->assign($account, $client, $request->user());
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('clients.account_assigned'));
    }

    public function reassignAccount(
        Request $request,
        Account $account,
        ClientAccountReassignmentService $reassignmentService,
    ): RedirectResponse {
        $this->authorize('update', $account);

        $validated = $request->validate([
            'target_client_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $targetClient = User::query()->findOrFail((int) $validated['target_client_id']);
        abort_unless($targetClient->role === UserRole::Client, 404);

        try {
            $reassignmentService->reassign($account, $targetClient, $request->user());
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('clients.account_reassigned'));
    }
}
