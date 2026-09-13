<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CommissionPayer;
use App\Enums\PaymentGatewayDriver;
use App\Enums\PaymentGatewayMode;
use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Support\PaymentSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentGatewayController extends Controller
{
    public function __construct()
    {
        // Payment-gateway admin pages are gated behind the payments module.
        $this->middleware('module:payments');
    }

    public function index(): View
    {
        // Only list gateways whose driver is currently provided by a registered
        // provider. Module-backed gateways (e.g. NowPayments) therefore disappear
        // from the panel entirely while their module is deactivated.
        $manager = app(PaymentGatewayManager::class);

        $gateways = PaymentGateway::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (PaymentGateway $gateway): bool => $manager->supports($gateway->driver))
            ->values();

        return view('admin.payment-gateways.index', [
            'gateways' => $gateways,
            'usdtTomanRate' => PaymentSettings::usdtTomanRate(),
        ]);
    }

    public function edit(string $driver): View|RedirectResponse
    {
        $gateway = $this->findGatewayOrFail($driver);

        // NowPayments lives in a module: if that module is inactive its admin view
        // and services are unavailable, so send the admin to activate it first.
        if ($gateway->driver === PaymentGatewayDriver::NowPayments
            && ! app(PaymentGatewayManager::class)->supports(PaymentGatewayDriver::NowPayments)) {
            return redirect()
                ->route('admin.payment-gateways.index')
                ->with('error', __('payment_gateways.nowpayments_module_inactive'));
        }

        $availableCurrencies = [];
        $currenciesError = null;

        if ($gateway->driver === PaymentGatewayDriver::NowPayments && $gateway->hasEncryptedConfigValue('api_key_enc')) {
            try {
                $availableCurrencies = app('nowpayments.catalog')->fetchAvailable(
                    $gateway,
                    (bool) request()->boolean('refresh_currencies'),
                );
            } catch (\Throwable $exception) {
                report($exception);
                $currenciesError = $exception->getMessage();
            }
        }

        return view($gateway->driver->adminEditView(), [
            'gateway' => $gateway,
            'usdtTomanRate' => PaymentSettings::usdtTomanRate(),
            'availableCurrencies' => $availableCurrencies,
            'currenciesError' => $currenciesError,
            'selectedPayCurrencies' => old('enabled_pay_currencies', $gateway->enabledNowPaymentsPayCurrencies()),
        ]);
    }

    public function updateGlobal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'usdt_toman_rate' => ['required', 'numeric', 'min:1'],
        ]);

        PaymentSettings::setUsdtTomanRate((string) $validated['usdt_toman_rate']);

        return redirect()
            ->route('admin.payment-gateways.index')
            ->with('success', __('payment_gateways.global_settings_saved'));
    }

    public function update(Request $request, string $driver): RedirectResponse
    {
        $gateway = $this->findGatewayOrFail($driver);

        $validated = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'mode' => ['required', Rule::enum(PaymentGatewayMode::class)],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'commission_fixed' => ['nullable', 'numeric', 'min:0'],
            'commission_payer' => ['required', Rule::enum(CommissionPayer::class)],
            'display_name' => ['required', 'string', 'max:120'],
        ]);

        $gateway->fill([
            'display_name' => $validated['display_name'],
            'is_enabled' => $request->boolean('is_enabled'),
            'mode' => $validated['mode'],
            'commission_percent' => number_format((float) ($validated['commission_percent'] ?? 0), 4, '.', ''),
            'commission_fixed' => number_format((float) ($validated['commission_fixed'] ?? 0), 2, '.', ''),
            'commission_payer' => $validated['commission_payer'],
        ]);

        if ($gateway->driver === PaymentGatewayDriver::NowPayments) {
            $nowValidated = $request->validate([
                'enabled_pay_currencies' => ['nullable', 'array'],
                'enabled_pay_currencies.*' => ['string', 'max:64'],
                'api_key' => ['nullable', 'string', 'max:500'],
                'ipn_secret' => ['nullable', 'string', 'max:500'],
            ]);

            $config = $gateway->config ?? [];
            $selected = array_values(array_unique(array_filter(array_map(
                static fn ($code): string => strtolower(trim((string) $code)),
                $nowValidated['enabled_pay_currencies'] ?? [],
            ))));
            $config['enabled_pay_currencies'] = $selected;
            unset($config['pay_currency']);
            $gateway->config = $config;

            $apiKey = trim((string) ($nowValidated['api_key'] ?? ''));
            if ($apiKey !== '') {
                $gateway->setEncryptedConfigValue('api_key_enc', $apiKey);
                Cache::forget('nowpayments_currencies.'.$gateway->id.'.'.$gateway->mode->value);
            }

            $ipnSecret = trim((string) ($nowValidated['ipn_secret'] ?? ''));
            if ($ipnSecret !== '') {
                $gateway->setEncryptedConfigValue('ipn_secret_enc', $ipnSecret);
            }
        }

        if ($gateway->driver === PaymentGatewayDriver::Zarinpal) {
            $zarinpalValidated = $request->validate([
                'merchant_id' => ['nullable', 'string', 'max:100'],
            ]);

            $merchantId = trim((string) ($zarinpalValidated['merchant_id'] ?? ''));
            if ($merchantId !== '') {
                $gateway->setEncryptedConfigValue('merchant_id_enc', $merchantId);
            }
        }

        if ($gateway->driver === PaymentGatewayDriver::CardToCard) {
            $cardValidated = $request->validate([
                'card_number' => ['required', 'string', 'max:32'],
                'card_holder' => ['required', 'string', 'max:120'],
                'bank_name' => ['nullable', 'string', 'max:120'],
                'instructions' => ['nullable', 'string', 'max:2000'],
            ]);

            $config = $gateway->config ?? [];
            $config['card_number'] = preg_replace('/\s+/', '', trim($cardValidated['card_number']));
            $config['card_holder'] = trim($cardValidated['card_holder']);
            $config['bank_name'] = trim((string) ($cardValidated['bank_name'] ?? ''));
            $config['instructions'] = trim((string) ($cardValidated['instructions'] ?? ''));
            $gateway->config = $config;
        }

        if ($gateway->is_enabled) {
            if ($gateway->driver === PaymentGatewayDriver::NowPayments && ! $gateway->hasEncryptedConfigValue('api_key_enc')) {
                return back()->withInput()->with('error', __('payment_gateways.nowpayments_api_key_missing'));
            }

            if ($gateway->driver === PaymentGatewayDriver::NowPayments && $gateway->enabledNowPaymentsPayCurrencies() === []) {
                return back()->withInput()->with('error', __('payment_gateways.pay_currencies_not_configured'));
            }

            if ($gateway->driver === PaymentGatewayDriver::Zarinpal && ! $gateway->hasEncryptedConfigValue('merchant_id_enc')) {
                return back()->withInput()->with('error', __('payment_gateways.zarinpal_merchant_missing'));
            }

            if ($gateway->driver === PaymentGatewayDriver::CardToCard && trim((string) $gateway->configValue('card_number', '')) === '') {
                return back()->withInput()->with('error', __('payment_gateways.card_to_card_destination_missing'));
            }
        }

        $gateway->save();

        return redirect()
            ->route('admin.payment-gateways.edit', $gateway->driver->value)
            ->with('success', __('payment_gateways.gateway_saved'));
    }

    protected function findGatewayOrFail(string $driver): PaymentGateway
    {
        $enum = PaymentGatewayDriver::tryFrom($driver);

        if ($enum === null) {
            abort(404);
        }

        $gateway = PaymentGateway::findByDriver($enum);

        if ($gateway === null) {
            abort(404);
        }

        return $gateway;
    }
}
