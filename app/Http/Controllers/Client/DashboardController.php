<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\PaymentRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClientDisplayPricingService;
use App\Services\EndUserService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        WalletService $walletService,
        ClientDisplayPricingService $pricingService,
        EndUserService $endUserService
    ): View {
        $client = $request->user();

        try {
            $wallet = $walletService->getOrCreateWallet($client);
            $portal = $endUserService->portalOwnerContext($client);
            $owner = $portal['owner'];
            $ownerRoleLabel = $portal['owner_role_label'];
            $paymentCards = $portal['payment_cards'];

            $accounts = Account::query()
                ->forClient($client)
                ->with(['package', 'packageDuration', 'server'])
                ->latest('created_at')
                ->get();

            $recentPayments = PaymentRequest::query()
                ->where('requester_user_id', $client->id)
                ->latest('created_at')
                ->limit(5)
                ->get();

            $recentTransactions = Transaction::query()
                ->where('user_id', $client->id)
                ->latest('created_at')
                ->limit(8)
                ->get();
        } catch (Throwable $exception) {
            report($exception);

            return view('client.dashboard', $this->fallbackPayload($client));
        }

        return view('client.dashboard', compact(
            'wallet',
            'owner',
            'ownerRoleLabel',
            'accounts',
            'recentPayments',
            'recentTransactions',
            'paymentCards',
        ));
    }

    /**
     * @return array{
     *     wallet: Wallet,
     *     owner: ?User,
     *     ownerRoleLabel: string,
     *     accounts: Collection<int, Account>,
     *     recentPayments: Collection<int, PaymentRequest>,
     *     recentTransactions: Collection<int, Transaction>,
     *     paymentCards: list<string>
     * }
     */
    protected function fallbackPayload(User $client): array
    {
        return [
            'wallet' => new Wallet([
                'user_id' => $client->id,
                'balance' => '0',
            ]),
            'owner' => $client->parent,
            'ownerRoleLabel' => $client->parent?->role->label() ?? '',
            'accounts' => collect(),
            'recentPayments' => collect(),
            'recentTransactions' => collect(),
            'paymentCards' => collect(),
        ];
    }
}
