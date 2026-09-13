<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MoneyCurrency;
use App\Enums\PaymentRequestStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\WalletAdjustment;
use App\Services\PaymentRequestService;
use App\Services\UserCurrencyService;
use App\Services\WalletAdjustmentService;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentRequestController extends Controller
{
    public function __construct(
        protected WalletService $walletService,
        protected WalletAdjustmentService $adjustmentService,
        protected PaymentRequestService $paymentRequestService,
        protected UserCurrencyService $userCurrencyService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PaymentRequest::class);

        $paymentRequests = PaymentRequest::query()
            ->with(['requester.parent', 'approver'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latestFirst()
            ->paginate(15, ['*'], 'requests_page')
            ->withQueryString();

        $adjustments = Schema::hasTable('wallet_adjustments')
            ? WalletAdjustment::query()
                ->with(['user', 'admin'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(15, ['*'], 'adjustments_page')
                ->withQueryString()
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15, 1, ['pageName' => 'adjustments_page']);

        $migrationPending = ! Schema::hasTable('wallet_adjustments');
        $chargeSummaryCards = $this->paymentRequestService->indexSummaryCards($request->user());

        return view('admin.payment-requests.index', compact('paymentRequests', 'adjustments', 'migrationPending', 'chargeSummaryCards'));
    }

    public function charge(): View
    {
        $this->authorize('viewAny', PaymentRequest::class);

        $users = User::query()
            ->whereIn('role', [UserRole::Agent, UserRole::Seller])
            ->with('wallets')
            ->orderBy('full_name')
            ->get();

        foreach ($users as $user) {
            foreach ($this->userCurrencyService->enabledCodes($user) as $code) {
                $this->walletService->getOrCreateWallet($user, $code);
            }
            $user->load('wallets');
        }

        $currencies = MoneyCurrency::sellable();
        $chargeToken = Str::uuid()->toString();
        session(['admin.wallet_charge_token' => $chargeToken]);

        return view('admin.payment-requests.charge', compact('users', 'chargeToken', 'currencies'));
    }

    public function storeCharge(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', PaymentRequest::class);

        $validated = $request->validate([
            'charge_token' => ['required', 'uuid'],
            'user_id' => [
                'required',
                Rule::exists('users', 'id')->where(function ($query): void {
                    $query->whereIn('role', [UserRole::Agent->value, UserRole::Seller->value]);
                }),
            ],
            'currency' => ['required', 'string', Rule::enum(MoneyCurrency::class)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $sessionToken = (string) session('admin.wallet_charge_token', '');
        if ($sessionToken === '' || ! hash_equals($sessionToken, $validated['charge_token'])) {
            return back()
                ->withInput()
                ->with('error', __('wallet.charge_duplicate_submit'));
        }

        session()->forget('admin.wallet_charge_token');

        $target = User::query()->findOrFail($validated['user_id']);
        $currency = MoneyCurrency::normalize($validated['currency']);

        try {
            $this->userCurrencyService->assertCanUseCurrency($target, $currency);
            $adjustment = $this->adjustmentService->adjust(
                $target,
                number_format((float) $validated['amount'], 2, '.', ''),
                $validated['direction'],
                $request->user(),
                $validated['note'] ?? null,
                'single',
                $currency
            );
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $wallet = $this->walletService->getOrCreateWallet($target, $currency);
        $formattedAmount = format_money($validated['amount'], $currency);
        $formattedBalance = format_money($wallet->balance ?? 0, $currency);

        $message = $validated['direction'] === 'credit'
            ? __('wallet.credit_success_detail', [
                'user' => $target->full_name,
                'amount' => $formattedAmount,
                'balance' => $formattedBalance,
            ])
            : __('wallet.debit_success_detail', [
                'user' => $target->full_name,
                'amount' => $formattedAmount,
                'balance' => $formattedBalance,
            ]);

        return redirect()
            ->route('admin.payment-requests.index')
            ->with('success', $message)
            ->with('highlight_adjustment_id', $adjustment->id);
    }

    public function storeBulkCharge(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', PaymentRequest::class);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->adjustmentService->bulkCredit(
            $request->user(),
            number_format((float) $validated['amount'], 2, '.', ''),
            $validated['note'] ?? null
        );

        $message = __('wallet.bulk_success', ['count' => $result['credited']]);

        if ($result['failed'] !== []) {
            return redirect()
                ->route('admin.payment-requests.index')
                ->with('warning', $message.' '.implode(' | ', array_slice($result['failed'], 0, 3)));
        }

        return redirect()
            ->route('admin.payment-requests.index')
            ->with('success', $message);
    }

    public function show(PaymentRequest $paymentRequest): View
    {
        $this->authorize('view', $paymentRequest);

        $paymentRequest->load(['requester', 'approver']);

        return view('admin.payment-requests.show', compact('paymentRequest'));
    }

    public function approve(Request $request, PaymentRequest $paymentRequest): RedirectResponse
    {
        $this->authorize('approve', $paymentRequest);

        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($paymentRequest, $validated, $request): void {
            $currency = $paymentRequest->moneyCurrency()->value;
            $this->walletService->credit(
                $paymentRequest->requester,
                (string) $paymentRequest->amount,
                TransactionType::Charge,
                [
                    'related_payment_request_id' => $paymentRequest->id,
                    'description' => 'Payment request approved',
                    'source_user_id' => $request->user()->id,
                    'currency' => $currency,
                ]
            );

            $paymentRequest->update([
                'status' => PaymentRequestStatus::Approved,
                'admin_note' => $validated['admin_note'] ?? null,
                'decided_at' => now(),
            ]);
        });

        return redirect()
            ->route('admin.payment-requests.index')
            ->with('success', __('menu.payment_approved'));
    }

    public function reject(Request $request, PaymentRequest $paymentRequest): RedirectResponse
    {
        $this->authorize('reject', $paymentRequest);

        $validated = $request->validate([
            'admin_note' => ['required', 'string', 'max:1000'],
        ]);

        $paymentRequest->update([
            'status' => PaymentRequestStatus::Rejected,
            'admin_note' => $validated['admin_note'],
            'decided_at' => now(),
        ]);

        return redirect()
            ->route('admin.payment-requests.index')
            ->with('success', __('menu.payment_rejected'));
    }
}
