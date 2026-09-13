<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KycProvider;
use App\Http\Controllers\Controller;
use App\Services\Kyc\KycService;
use App\Support\KycSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KycSettingsController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', \App\Models\User::class);

        return view('admin.kyc.settings', [
            'enabled' => KycSettings::enabled(),
            'provider' => KycSettings::provider(),
            'hasApiKey' => KycSettings::hasApiKey(),
            'lastTestOk' => KycSettings::lastTestOk(),
            'lastTestAt' => KycSettings::lastTestAt(),
            'providers' => KycProvider::cases(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', \App\Models\User::class);

        $validated = $request->validate([
            'kyc_provider' => ['required', Rule::enum(KycProvider::class)],
            'kyc_api_key' => ['nullable', 'string', 'max:2000'],
        ]);

        KycSettings::setEnabled($request->boolean('kyc_enabled'));
        KycSettings::setProvider($validated['kyc_provider']);

        $newKey = trim((string) ($validated['kyc_api_key'] ?? ''));
        if ($newKey !== '') {
            KycSettings::setApiKey($newKey);
        }

        return redirect()
            ->route('admin.kyc.settings')
            ->with('success', __('kyc.settings_saved'));
    }

    public function test(KycService $kycService): RedirectResponse
    {
        $this->authorize('viewAny', \App\Models\User::class);

        if (! KycSettings::hasApiKey()) {
            return redirect()
                ->route('admin.kyc.settings')
                ->with('error', __('kyc.api_key_placeholder'));
        }

        $result = $kycService->testConnection();

        return redirect()
            ->route('admin.kyc.settings')
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
