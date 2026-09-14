<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AccountKycVerification;
use App\Models\Package;
use App\Services\Kyc\KycService;
use App\Support\KycSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ManagesAccountKyc
{
    public function kycSubmit(Request $request, KycService $kycService): JsonResponse
    {
        $this->authorize('create', \App\Models\Account::class);

        if (! KycSettings::isReady()) {
            return response()->json(['message' => __('kyc.not_configured')], 422);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'national_code' => ['required', 'string', 'max:20'],
            'birth_date' => ['required', 'string', 'max:20'],
            'mobile' => ['required', 'string', 'max:20'],
            'document' => ['required', 'file', 'max:'.(int) config('kyc.document_max_kb', 5120), 'mimes:'.implode(',', config('kyc.document_mimes', ['jpg', 'jpeg', 'png', 'webp', 'pdf']))],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'owner_seller_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (! empty($validated['package_id'])) {
            $package = Package::query()->findOrFail((int) $validated['package_id']);
            if (! $package->kyc_required) {
                return response()->json(['message' => 'این پکیج نیاز به احراز ندارد.'], 422);
            }
        }

        try {
            $verification = $kycService->submitDraft($request->user(), $validated, $request->file('document'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => __('app.saved'),
            'verification' => $kycService->publicPayload($verification, $request->user()->role === \App\Enums\UserRole::Admin),
        ]);
    }

    public function kycVerify(Request $request, AccountKycVerification $kycVerification, KycService $kycService): JsonResponse
    {
        $this->authorize('create', \App\Models\Account::class);

        try {
            $verification = $kycService->verify($kycVerification, $request->user());
        } catch (\Throwable $exception) {
            $fresh = $kycVerification->fresh();

            return response()->json([
                'message' => $exception->getMessage(),
                'verification' => $fresh
                    ? $kycService->publicPayload($fresh, $request->user()->role === \App\Enums\UserRole::Admin)
                    : null,
            ], 422);
        }

        return response()->json([
            'message' => __('kyc.verified_ready'),
            'verification' => $kycService->publicPayload($verification, $request->user()->role === \App\Enums\UserRole::Admin),
        ]);
    }

    public function kycRequestReset(Request $request, AccountKycVerification $kycVerification, KycService $kycService): JsonResponse
    {
        $this->authorize('create', \App\Models\Account::class);

        try {
            $verification = $kycService->requestReset($kycVerification, $request->user());
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => __('kyc.reset_requested'),
            'verification' => $kycService->publicPayload($verification, $request->user()->role === \App\Enums\UserRole::Admin),
        ]);
    }
}
