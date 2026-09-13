<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KycVerificationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AccountKycVerification;
use App\Services\Kyc\KycService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KycVerificationController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->role === UserRole::Admin, 403);

        $items = AccountKycVerification::query()
            ->with(['initiatedBy', 'ownerSeller', 'package', 'account'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.kyc.index', [
            'items' => $items,
            'statuses' => KycVerificationStatus::cases(),
        ]);
    }

    public function show(Request $request, AccountKycVerification $kycVerification): View
    {
        abort_unless($request->user()?->role === UserRole::Admin, 403);
        $kycVerification->load(['initiatedBy', 'ownerSeller', 'package', 'account', 'resetByAdmin']);

        return view('admin.kyc.show', [
            'item' => $kycVerification,
        ]);
    }

    public function document(Request $request, AccountKycVerification $kycVerification, KycService $kycService): StreamedResponse
    {
        abort_unless($request->user()?->role === UserRole::Admin, 403);

        return $kycService->openDocumentStream($kycVerification);
    }

    public function reset(Request $request, AccountKycVerification $kycVerification, KycService $kycService): RedirectResponse
    {
        abort_unless($request->user()?->role === UserRole::Admin, 403);

        try {
            $kycService->adminReset($kycVerification, $request->user());
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('kyc.reset_done'));
    }
}
