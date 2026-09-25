<?php

namespace App\Services;

use App\Enums\MoneyCurrency;
use App\Enums\PaymentRequestStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentRequestService
{
    public function __construct(
        protected WalletService $walletService,
        protected ActivityLogService $activityLogService,
        protected UserCurrencyService $userCurrencyService,
    ) {}

    public function submit(
        User $requester,
        string $amount,
        string $tracking,
        string $cardLast4,
        ?UploadedFile $receipt = null,
        ?string $requesterNote = null,
        MoneyCurrency|string|null $currency = null
    ): PaymentRequest {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Payment request amount must be greater than zero.');
        }

        $currencyEnum = MoneyCurrency::normalize(
            $currency instanceof MoneyCurrency
                ? $currency->value
                : ($currency ?? $this->userCurrencyService->settlementCurrency($requester)->value)
        );
        $this->userCurrencyService->assertCanUseCurrency($requester, $currencyEnum);

        $approver = $this->resolveApprover($requester);
        if ($approver->role === UserRole::Agent) {
            $this->userCurrencyService->assertCanUseCurrency($approver, $currencyEnum);
        }

        $receiptPath = null;

        if ($receipt !== null) {
            $receiptPath = $receipt->store('payment-receipts', 'local');
        }

        $payload = [
            'requester_user_id' => $requester->id,
            'approver_user_id' => $approver->id,
            'amount' => $amount,
            'tracking_number' => $tracking,
            'card_last4' => $cardLast4,
            'receipt_image_path' => $receiptPath,
            'status' => PaymentRequestStatus::Pending,
            'requester_note' => $requesterNote,
            'created_at' => now(),
        ];

        if (\Illuminate\Support\Facades\Schema::hasColumn('payment_requests', 'currency')) {
            $payload['currency'] = $currencyEnum->value;
        }

        $request = PaymentRequest::query()->create($payload);

        $this->activityLogService->log($requester, 'payment_request.submitted', $request, [
            'amount' => $amount,
            'currency' => $currencyEnum->value,
            'approver_id' => $approver->id,
        ]);

        return $request;
    }

    public function approve(PaymentRequest $request, User $approver, ?string $adminNote = null): PaymentRequest
    {
        $this->assertPending($request);

        if ($request->approver_user_id !== $approver->id && $approver->role !== UserRole::Admin) {
            throw new InvalidArgumentException('Only the assigned approver can approve this request.');
        }

        return DB::transaction(function () use ($request, $approver, $adminNote) {
            // Re-fetch under a row lock and re-check the status INSIDE the transaction. The
            // assertPending() above is only an in-memory read taken before the transaction
            // opens, so two concurrent approvals (or an approval racing a rejection) both pass
            // it and the requester's wallet is credited twice for one receipt — and when the
            // approver is the Admin the matching debit comes out of an infinite wallet, so
            // nothing absorbs the duplicate credit.
            $request = PaymentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            $this->assertPending($request);

            $amount = (string) $request->amount;
            $currency = $request->moneyCurrency()->value;
            $context = [
                'related_payment_request_id' => $request->id,
                'source_user_id' => $request->requester_user_id,
                'description' => 'Payment request approved',
                'currency' => $currency,
            ];

            $this->walletService->debit(
                $approver,
                $amount,
                TransactionType::Charge,
                $context
            );

            $this->walletService->credit(
                $request->requester,
                $amount,
                TransactionType::Charge,
                $context
            );

            $request->update([
                'status' => PaymentRequestStatus::Approved,
                'admin_note' => $adminNote,
                'decided_at' => now(),
            ]);

            $this->activityLogService->log($approver, 'payment_request.approved', $request);

            return $request->fresh();
        });
    }

    public function reject(PaymentRequest $request, User $approver, ?string $adminNote = null): PaymentRequest
    {
        $this->assertPending($request);

        if ($request->approver_user_id !== $approver->id && $approver->role !== UserRole::Admin) {
            throw new InvalidArgumentException('Only the assigned approver can reject this request.');
        }

        return DB::transaction(function () use ($request, $approver, $adminNote) {
            // Rejection has to take the same lock as approve(): without it a rejection and an
            // approval of the same pending request can interleave, so the request ends up
            // rejected while the wallet credit from the racing approval still happened.
            $request = PaymentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            $this->assertPending($request);

            $request->update([
                'status' => PaymentRequestStatus::Rejected,
                'admin_note' => $adminNote,
                'decided_at' => now(),
            ]);

            $this->activityLogService->log($approver, 'payment_request.rejected', $request);

            return $request->fresh();
        });
    }

    protected function resolveApprover(User $requester): User
    {
        $parent = $requester->parent;

        if ($parent === null) {
            $admin = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();

            if ($admin === null) {
                throw new InvalidArgumentException('No approver found for payment request.');
            }

            return $admin;
        }

        return $parent;
    }

    protected function assertPending(PaymentRequest $request): void
    {
        if ($request->status !== PaymentRequestStatus::Pending) {
            throw new InvalidArgumentException('Payment request is not pending.');
        }
    }

    /**
     * @return list<array{label: string, value: int, tone: string}>
     */
    public function indexSummaryCards(User $viewer): array
    {
        $pending = PaymentRequest::query()->where('status', PaymentRequestStatus::Pending);

        if ($viewer->role === UserRole::Admin) {
            return [
                [
                    'label' => __('wallet.summary_incoming_pending'),
                    'value' => (clone $pending)->where('approver_user_id', $viewer->id)->count(),
                    'tone' => 'warning',
                ],
                [
                    'label' => __('wallet.summary_agent_queue_pending'),
                    'value' => (clone $pending)->whereHas('approver', fn ($query) => $query->where('role', UserRole::Agent))->count(),
                    'tone' => 'amber',
                ],
            ];
        }

        if ($viewer->role === UserRole::Agent) {
            return [
                [
                    'label' => __('wallet.summary_incoming_pending'),
                    'value' => (clone $pending)->where('approver_user_id', $viewer->id)->count(),
                    'tone' => 'warning',
                ],
                [
                    'label' => __('wallet.summary_outgoing_pending'),
                    'value' => (clone $pending)->where('requester_user_id', $viewer->id)->count(),
                    'tone' => 'amber',
                ],
            ];
        }

        if ($viewer->role === UserRole::Seller) {
            return [
                [
                    'label' => __('wallet.summary_outgoing_pending'),
                    'value' => (clone $pending)->where('requester_user_id', $viewer->id)->count(),
                    'tone' => 'warning',
                ],
            ];
        }

        return [];
    }
}
