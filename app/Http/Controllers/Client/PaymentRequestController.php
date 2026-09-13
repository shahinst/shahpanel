<?php

namespace App\Http\Controllers\Client;

use App\Enums\PaymentRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\EndUserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentRequestController extends Controller
{
    public function index(Request $request): View
    {
        $paymentRequests = PaymentRequest::query()
            ->where('requester_user_id', $request->user()->id)
            ->with('approver')
            ->latest('created_at')
            ->paginate(15);

        return view('client.payment-requests.index', compact('paymentRequests'));
    }

    public function create(Request $request, EndUserService $endUserService): View|RedirectResponse
    {
        try {
            $portal = $endUserService->portalOwnerContext($request->user());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('client.dashboard')
                ->with('error', $exception->getMessage());
        }

        return view('client.payment-requests.create', [
            'owner' => $portal['owner'],
            'ownerRoleLabel' => $portal['owner_role_label'],
            'cards' => $portal['payment_cards'],
        ]);
    }

    public function store(Request $request, EndUserService $endUserService): RedirectResponse
    {
        $this->authorize('create', PaymentRequest::class);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1000', 'max:999999999'],
            'tracking_number' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9\-_\x{0600}-\x{06FF}\s]+$/u'],
            'card_last4' => ['required', 'digits:4'],
            'requester_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $client = $request->user();
        $approver = $endUserService->resolvePortalOwner($client);

        PaymentRequest::query()->create([
            ...$validated,
            'requester_user_id' => $client->id,
            'approver_user_id' => $approver->id,
            'status' => PaymentRequestStatus::Pending,
            'created_at' => now(),
        ]);

        return redirect()
            ->route('client.payment-requests.index')
            ->with('success', __('clients.charge_request_sent'));
    }
}
