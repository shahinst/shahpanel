<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentGatewayDriver;
use App\Http\Controllers\Controller;
use App\Models\GatewayPayment;
use App\Services\PaymentGateways\GatewayPaymentService;
use App\Services\PaymentGateways\PaymentGatewayException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GatewayPaymentController extends Controller
{
    public function __construct(
        protected GatewayPaymentService $gatewayPaymentService,
    ) {
        // Online payment history is gated behind the payments module.
        $this->middleware('module:payments');
    }

    public function index(Request $request): View
    {
        $payments = GatewayPayment::query()
            ->with(['user', 'paymentGateway', 'reviewer'])
            ->when($request->filled('driver'), fn ($q) => $q->where('driver', $request->string('driver')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $q->where(function ($query) use ($term): void {
                    $query->where('uuid', 'like', $term)
                        ->orWhere('tracking_number', 'like', $term)
                        ->orWhereHas('user', fn ($userQuery) => $userQuery
                            ->where('username', 'like', $term)
                            ->orWhere('full_name', 'like', $term));
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $pendingCardCount = GatewayPayment::query()
            ->where('driver', PaymentGatewayDriver::CardToCard)
            ->where('status', GatewayPaymentStatus::Processing)
            ->count();

        return view('admin.gateway-payments.index', [
            'payments' => $payments,
            'pendingCardCount' => $pendingCardCount,
            'drivers' => PaymentGatewayDriver::cases(),
            'statuses' => GatewayPaymentStatus::cases(),
        ]);
    }

    public function show(GatewayPayment $gatewayPayment): View
    {
        $gatewayPayment->load(['user', 'paymentGateway', 'reviewer', 'transactions']);

        return view('admin.gateway-payments.show', [
            'payment' => $gatewayPayment,
        ]);
    }

    public function approve(Request $request, GatewayPayment $gatewayPayment): RedirectResponse
    {
        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->gatewayPaymentService->approveCardToCard(
                $gatewayPayment,
                $request->user(),
                $validated['admin_note'] ?? null,
            );
        } catch (PaymentGatewayException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.gateway-payments.show', $gatewayPayment)
            ->with('success', __('payment_gateways.approved'));
    }

    public function reject(Request $request, GatewayPayment $gatewayPayment): RedirectResponse
    {
        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->gatewayPaymentService->rejectCardToCard(
                $gatewayPayment,
                $request->user(),
                $validated['admin_note'] ?? null,
            );
        } catch (PaymentGatewayException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.gateway-payments.show', $gatewayPayment)
            ->with('success', __('payment_gateways.rejected'));
    }
}
