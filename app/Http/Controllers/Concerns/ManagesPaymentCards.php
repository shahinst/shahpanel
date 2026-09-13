<?php

namespace App\Http\Controllers\Concerns;

use App\Models\PaymentCard;
use App\Services\PaymentCardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

trait ManagesPaymentCards
{
    public function indexPaymentCards(Request $request, PaymentCardService $cardService): View
    {
        if (! Schema::hasTable('payment_cards')) {
            return view('shared.payment-cards.migration-required', [
                'panel' => $this->clientSettingsPanel(),
            ]);
        }

        $user = $request->user();

        return view('shared.payment-cards.index', [
            'panel' => $this->clientSettingsPanel(),
            'cards' => $cardService->cardsForOwner($user),
            'pendingApprovals' => in_array($user->role->value, ['admin', 'agent'], true)
                ? $cardService->pendingApprovalsFor($user)
                : collect(),
            'uplineLabel' => $cardService->uplineApprover($user)?->role->label(),
        ]);
    }

    public function storePaymentCard(Request $request, PaymentCardService $cardService): RedirectResponse
    {
        if (! Schema::hasTable('payment_cards')) {
            return back()->with('error', __('clients.migration_required'));
        }

        $validated = $request->validate([
            'card_number' => ['required', 'string', 'max:19'],
            'card_holder' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $cardService->create($user, $validated);

        $message = $user->role->value === 'admin'
            ? __('app.saved')
            : __('payment_cards.pending_approval_notice');

        return redirect()
            ->route($this->clientSettingsPanel().'.client-payment-card.edit')
            ->with('success', $message);
    }

    public function requestDeletePaymentCard(
        Request $request,
        PaymentCard $paymentCard,
        PaymentCardService $cardService
    ): RedirectResponse {
        if ((int) $paymentCard->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        $cardService->requestDeletion($paymentCard, $request->user());

        $message = $request->user()->role->value === 'admin'
            ? __('payment_cards.deleted')
            : __('payment_cards.deletion_pending_notice');

        return redirect()
            ->route($this->clientSettingsPanel().'.client-payment-card.edit')
            ->with('success', $message);
    }

    public function approvePaymentCard(
        Request $request,
        PaymentCard $paymentCard,
        PaymentCardService $cardService
    ): RedirectResponse {
        $cardService->approve($paymentCard, $request->user());

        return back()->with('success', __('payment_cards.approved'));
    }

    public function rejectPaymentCard(
        Request $request,
        PaymentCard $paymentCard,
        PaymentCardService $cardService
    ): RedirectResponse {
        $cardService->reject($paymentCard, $request->user());

        return back()->with('success', __('payment_cards.rejected'));
    }
}
