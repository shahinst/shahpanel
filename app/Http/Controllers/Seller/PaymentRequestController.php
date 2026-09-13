<?php

namespace App\Http\Controllers\Seller;

use App\Enums\PaymentRequestStatus;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\PaymentRequestService;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentRequestController extends Controller
{
    public function __construct(
        protected WalletService $walletService,
        protected PaymentRequestService $paymentRequestService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PaymentRequest::class);

        $user = $request->user();

        $paymentRequests = PaymentRequest::query()
            ->where(function ($query) use ($user): void {
                $query->where('requester_user_id', $user->id)
                    ->orWhere('approver_user_id', $user->id);
            })
            ->with(['approver', 'requester'])
            ->latestFirst()
            ->paginate(15)
            ->withQueryString();

        $chargeSummaryCards = $this->paymentRequestService->indexSummaryCards($user);

        return view('seller.payment-requests.index', compact('paymentRequests', 'chargeSummaryCards'));
    }

    public function create(Request $request, \App\Services\PaymentCardService $cardService): View
    {
        $this->authorize('create', PaymentRequest::class);

        return view('seller.payment-requests.create', [
            'payeeCards' => $cardService->payeeCardsFor($request->user()),
            'payee' => $cardService->payeeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PaymentRequest::class);

        $user = $request->user();
        $currency = \App\Enums\MoneyCurrency::normalize(
            $request->input('currency', $user->settlementMoneyCurrency()->value)
        );
        $minAmount = $currency === \App\Enums\MoneyCurrency::IRT ? 1000 : 0.01;

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:'.$minAmount],
            'currency' => ['nullable', 'string', \Illuminate\Validation\Rule::enum(\App\Enums\MoneyCurrency::class)],
            'tracking_number' => ['required', 'string', 'max:100'],
            'card_last4' => ['required', 'digits:4'],
            'requester_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $approver = $user->parent;

        if ($approver === null) {
            return back()->withErrors(['amount' => __('validation.custom.approver_missing')]);
        }

        try {
            $this->paymentRequestService->submit(
                $user,
                number_format((float) $validated['amount'], 2, '.', ''),
                $validated['tracking_number'],
                $validated['card_last4'],
                null,
                $validated['requester_note'] ?? null,
                $currency
            );
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('seller.payment-requests.index')
            ->with('success', __('app.saved'));
    }

    public function show(PaymentRequest $paymentRequest): View
    {
        $this->authorize('view', $paymentRequest);

        $paymentRequest->load('approver');

        return view('seller.payment-requests.show', [
            'paymentRequest' => $paymentRequest,
            'approveRoute' => route('seller.payment-requests.approve', $paymentRequest),
            'rejectRoute' => route('seller.payment-requests.reject', $paymentRequest),
        ]);
    }

    public function approve(Request $request, PaymentRequest $paymentRequest): RedirectResponse
    {
        $this->authorize('approve', $paymentRequest);

        try {
            $this->paymentRequestService->approve(
                $paymentRequest,
                $request->user(),
                $request->input('admin_note')
            );
        } catch (InsufficientWalletBalanceException) {
            return back()->with('error', __('wallet.approver_insufficient_charge_generic'));
        }

        return back()->with('success', __('wallet.approve_charge_success'));
    }

    public function reject(Request $request, PaymentRequest $paymentRequest): RedirectResponse
    {
        $this->authorize('reject', $paymentRequest);

        $request->validate(['admin_note' => ['required', 'string', 'max:1000']]);

        $paymentRequest->update([
            'status' => PaymentRequestStatus::Rejected,
            'decided_at' => now(),
            'admin_note' => $request->input('admin_note'),
        ]);

        return back()->with('success', __('menu.payment_rejected'));
    }
}
