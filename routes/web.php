<?php

use App\Http\Controllers\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\PackageController as AdminPackageController;
use App\Http\Controllers\Admin\PackageCategoryController as AdminPackageCategoryController;
use App\Http\Controllers\Admin\PaymentRequestController as AdminPaymentRequestController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\BroadcastController as AdminBroadcastController;
use App\Http\Controllers\Admin\AccountingController as AdminAccountingController;
use App\Http\Controllers\Admin\AutomationController as AdminAutomationController;
use App\Http\Controllers\Admin\FinancialPlanTemplateController as AdminFinancialPlanTemplateController;
use App\Http\Controllers\Admin\AgentFinancialPlanPurchaseController as AdminAgentFinancialPlanPurchaseController;
use App\Http\Controllers\Admin\MaintenanceController as AdminMaintenanceController;
use App\Http\Controllers\Admin\ModuleController as AdminModuleController;
use App\Http\Controllers\Admin\SellerController as AdminSellerController;
use App\Http\Controllers\Admin\ServerClientImportController as AdminServerClientImportController;
use App\Http\Controllers\Admin\ServerOperationsController as AdminServerOperationsController;
use App\Http\Controllers\Admin\ServerController as AdminServerController;
use App\Http\Controllers\Admin\SecurityController as AdminSecurityController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\ApplicationLogController as AdminApplicationLogController;
use App\Http\Controllers\Admin\ServerBackupController as AdminServerBackupController;
use App\Http\Controllers\Admin\SmsSettingsController as AdminSmsSettingsController;
use App\Http\Controllers\Admin\KycSettingsController as AdminKycSettingsController;
use App\Http\Controllers\Admin\KycVerificationController as AdminKycVerificationController;
use App\Http\Controllers\Admin\PaymentGatewayController as AdminPaymentGatewayController;
use App\Http\Controllers\Admin\GatewayPaymentController as AdminGatewayPaymentController;
use App\Http\Controllers\Admin\GiftAccountController as AdminGiftAccountController;
use App\Http\Controllers\Admin\GiftRewardController as AdminGiftRewardController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Agent\ClientController as AgentClientController;
use App\Http\Controllers\Agent\AccountingCorrectionController as AgentAccountingCorrectionController;
use App\Http\Controllers\Agent\AccountingController as AgentAccountingController;
use App\Http\Controllers\Agent\FinancialPlanController as AgentFinancialPlanController;
use App\Http\Controllers\Agent\AccountController as AgentAccountController;
use App\Http\Controllers\Agent\BroadcastController as AgentBroadcastController;
use App\Http\Controllers\Agent\DashboardController as AgentDashboardController;
use App\Http\Controllers\Agent\InvoiceController as AgentInvoiceController;
use App\Http\Controllers\Agent\PaymentRequestController as AgentPaymentRequestController;
use App\Http\Controllers\Agent\SellerController as AgentSellerController;
use App\Http\Controllers\Agent\StorefrontController as AgentStorefrontController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\LoginCaptchaController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Portal\ClientPortalController;
use App\Http\Controllers\PortalIconController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\TwoFactorSettingsController;
use App\Http\Controllers\Seller\ClientController as SellerClientController;
use App\Http\Controllers\Seller\AccountingController as SellerAccountingController;
use App\Http\Controllers\Seller\AccountController as SellerAccountController;
use App\Http\Controllers\Seller\DashboardController as SellerDashboardController;
use App\Http\Controllers\Seller\InvoiceController as SellerInvoiceController;
use App\Http\Controllers\Seller\PaymentRequestController as SellerPaymentRequestController;
use App\Http\Controllers\Seller\StorefrontController as SellerStorefrontController;
use App\Http\Controllers\Seller\TransactionController as SellerTransactionController;
use App\Http\Controllers\Client\AccountController as ClientAccountController;
use App\Http\Controllers\BroadcastBannerController;
use App\Http\Controllers\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Client\ShopController as ClientShopController;
use App\Http\Controllers\Client\PaymentRequestController as ClientPaymentRequestController;
use App\Http\Controllers\GatewayTopUpController;
use App\Http\Controllers\Admin\ClientPortalSettingsController as AdminClientPortalSettingsController;
use App\Http\Controllers\Admin\SupportTicketController as AdminSupportTicketController;
use App\Http\Controllers\Agent\ClientPortalSettingsController as AgentClientPortalSettingsController;
use App\Http\Controllers\Agent\SupportTicketController as AgentSupportTicketController;
use App\Http\Controllers\Seller\ClientPortalSettingsController as SellerClientPortalSettingsController;
use App\Http\Controllers\Seller\SupportTicketController as SellerSupportTicketController;
use App\Http\Controllers\Storefront\PublicStorefrontController;
use App\Support\PortalPaths;
use Illuminate\Support\Facades\Route;

$adminPath = PortalPaths::slug('admin');
$agentPath = PortalPaths::slug('agent');
$sellerPath = PortalPaths::slug('seller');
$loginPortals = PortalPaths::loginPortalPattern();

$adminMiddleware = ['auth', 'role:admin', 'log.activity'];
if (class_exists(\App\Http\Middleware\RestrictAdminByIp::class)) {
    $adminMiddleware[] = 'admin.ip';
}

Route::get('/', [AuthController::class, 'showHome'])->name('home');
Route::get('/login', [AuthController::class, 'showHome'])->middleware('ip.guard')->name('login');
Route::get('/login/captcha', [LoginCaptchaController::class, 'refresh'])
    ->middleware('throttle:login-captcha')
    ->name('login.captcha');
Route::post('/login', [AuthController::class, 'loginUnified'])->middleware(['throttle:login', 'ip.guard'])->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/two-factor/challenge', [TwoFactorController::class, 'showChallenge'])->name('auth.two-factor.challenge');
Route::post('/two-factor/challenge', [TwoFactorController::class, 'verifyChallenge'])
    ->middleware('throttle:two-factor')
    ->name('auth.two-factor.verify');

Route::prefix('{portal}')->where(['portal' => $loginPortals])->group(function (): void {
    Route::get('login', [AuthController::class, 'showLoginForm'])->middleware('ip.guard')->name('auth.login');
    Route::post('login', [AuthController::class, 'login'])->middleware(['throttle:login', 'ip.guard'])->name('auth.login.submit');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth')->name('auth.logout');
    Route::get('two-factor/challenge', fn () => redirect()->route('auth.two-factor.challenge'));
});

Route::middleware('noindex')->group(function (): void {
    Route::get('/portal/{token}', [ClientPortalController::class, 'show'])->name('portal.show');
    Route::get('/portal/{token}/stats', [ClientPortalController::class, 'stats'])
        ->middleware('throttle:portal-stats')
        ->name('portal.stats');
    Route::get('/portal/{token}/config/download', [ClientPortalController::class, 'downloadConfig'])->name('portal.config.download');
    Route::get('/portal/{token}/config/qr', [ClientPortalController::class, 'downloadQr'])->name('portal.config.qr');
});
Route::get('/portal-icons/{filename}', [PortalIconController::class, 'show'])
    ->where('filename', '[a-f0-9]{40}\.(png|jpe?g|webp|svg|ico)')
    ->name('portal-icons.show');
Route::get('/s/{slug}', [PublicStorefrontController::class, 'show'])->name('storefront.public');



// The payment webhooks (zarinpal, nowpayments) live in modules/payments and are
// registered by its service provider, so they disappear when the module is
// deactivated. Keeping a copy here would register the name twice and break
// `route:cache`.

Route::post('impersonate/leave', [ImpersonationController::class, 'leave'])
    ->middleware(['auth', 'throttle:impersonation'])
    ->name('impersonate.leave');


Route::prefix($adminPath)->name('admin.')->middleware($adminMiddleware)->group(function (): void {
    Route::get('login-firewall', [\App\Http\Controllers\Admin\LoginFirewallController::class, 'index'])->name('login-firewall.index');
    Route::post('login-firewall/block', [\App\Http\Controllers\Admin\LoginFirewallController::class, 'blockManually'])->name('login-firewall.block');
    Route::post('login-firewall/{blockedIp}/unblock', [\App\Http\Controllers\Admin\LoginFirewallController::class, 'unblock'])->name('login-firewall.unblock');
    Route::post('login-firewall/whitelist', [\App\Http\Controllers\Admin\LoginFirewallController::class, 'storeWhitelist'])->name('login-firewall.whitelist.store');
    Route::delete('login-firewall/whitelist/{whitelist}', [\App\Http\Controllers\Admin\LoginFirewallController::class, 'destroyWhitelist'])->name('login-firewall.whitelist.destroy');

    Route::get('web-shield', [\App\Http\Controllers\Admin\WebShieldController::class, 'index'])->name('web-shield.index');
    Route::post('web-shield/ban', [\App\Http\Controllers\Admin\WebShieldController::class, 'ban'])->name('web-shield.ban');
    Route::post('web-shield/unban', [\App\Http\Controllers\Admin\WebShieldController::class, 'unban'])->name('web-shield.unban');
    Route::post('web-shield/whitelist', [\App\Http\Controllers\Admin\WebShieldController::class, 'whitelistAdd'])->name('web-shield.whitelist.add');
    Route::post('web-shield/whitelist/delete', [\App\Http\Controllers\Admin\WebShieldController::class, 'whitelistDel'])->name('web-shield.whitelist.del');
    Route::post('web-shield/whitelist/me', [\App\Http\Controllers\Admin\WebShieldController::class, 'whitelistMe'])->name('web-shield.whitelist.me');
    Route::post('web-shield/whitelist/sync', [\App\Http\Controllers\Admin\WebShieldController::class, 'syncDbWhitelist'])->name('web-shield.whitelist.sync');
    Route::post('web-shield/scan', [\App\Http\Controllers\Admin\WebShieldController::class, 'scanStart'])->name('web-shield.scan');
    Route::post('web-shield/service', [\App\Http\Controllers\Admin\WebShieldController::class, 'service'])->name('web-shield.service');
    Route::post('web-shield/init', [\App\Http\Controllers\Admin\WebShieldController::class, 'init'])->name('web-shield.init');

    Route::get('api-tokens', [\App\Http\Controllers\Admin\ApiTokenAdminController::class, 'index'])->name('api-tokens.index');
    Route::delete('api-tokens/{apiToken}', [\App\Http\Controllers\Admin\ApiTokenAdminController::class, 'destroy'])->name('api-tokens.destroy');

    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/system-health', [AdminDashboardController::class, 'systemHealth'])->name('dashboard.system-health');
    Route::get('dashboard/server-stats', [AdminDashboardController::class, 'serverStats'])->name('dashboard.server-stats');

    Route::resource('users', AdminUserController::class)->except(['show']);
    Route::resource('sellers', AdminSellerController::class)->except(['show'])->parameters(['sellers' => 'seller']);
    Route::post('sellers/{seller}', [AdminSellerController::class, 'update']);
    Route::post('sellers/{seller}/promote', [AdminSellerController::class, 'promote'])->name('sellers.promote');

    Route::get('clients', [AdminClientController::class, 'index'])->name('clients.index');
    Route::get('clients/create', [AdminClientController::class, 'create'])->name('clients.create');
    Route::post('clients', [AdminClientController::class, 'store'])->name('clients.store');
    Route::get('clients/{client}', [AdminClientController::class, 'show'])->name('clients.show');
    Route::get('clients/{client}/accounts/{account}', [AdminClientController::class, 'showAccount'])->name('clients.accounts.show');
    Route::post('clients/{client}/accounts/{account}/assign', [AdminClientController::class, 'assignAccount'])->name('clients.accounts.assign');
    Route::post('clients/accounts/{account}/reassign', [AdminClientController::class, 'reassignAccount'])->name('clients.accounts.reassign');
    Route::post('clients/{user}/impersonate', [ImpersonationController::class, 'start'])
        ->middleware('throttle:impersonation')
        ->name('clients.impersonate');

    Route::resource('servers', AdminServerController::class)->except(['show']);
    Route::get('servers/{server}', [AdminServerController::class, 'show'])->name('servers.show');
    Route::post('servers/{server}/test-connection', [AdminServerOperationsController::class, 'testConnection'])->name('servers.test-connection');
    Route::post('servers/{server}/sync-interfaces', [AdminServerOperationsController::class, 'syncInterfaces'])->name('servers.sync-interfaces');
    Route::post('servers/{server}/sync-inbounds', [AdminServerOperationsController::class, 'syncInterfaces'])->name('servers.sync-inbounds');
    Route::post('servers/{server}/sync-profiles', [AdminServerOperationsController::class, 'pushProfiles'])->name('servers.sync-profiles');
    Route::post('servers/{server}/refresh-from-router', [AdminServerOperationsController::class, 'pullFromRouter'])->name('servers.refresh-from-router');
    Route::post('servers/{server}/sync-accounts', [AdminServerOperationsController::class, 'syncAccounts'])->name('servers.sync-accounts');
    Route::post('servers/{server}/push-accounts', [AdminServerOperationsController::class, 'pushAccounts'])->name('servers.push-accounts');
    Route::post('servers/{server}/migrate-sanaei', [AdminServerOperationsController::class, 'migrateSanaeiFrom'])->name('servers.migrate-sanaei');
    Route::post('servers/{server}/sync-traffic', [AdminServerOperationsController::class, 'syncTraffic'])->name('servers.sync-traffic');
    Route::post('servers/{server}/wireguard-interfaces', [AdminServerOperationsController::class, 'storeWireguardInterface'])->name('servers.wireguard-interfaces.store');
    Route::put('servers/{server}/wireguard-interfaces/{serverInterface}', [AdminServerOperationsController::class, 'updateWireguardInterface'])->name('servers.wireguard-interfaces.update');
    Route::post('servers/{server}/ppp-profiles', [AdminServerOperationsController::class, 'storePppProfile'])->name('servers.ppp-profiles.store');
    Route::post('servers/{server}/ovpn-profile', [AdminServerOperationsController::class, 'storeOvpnProfile'])->name('servers.ovpn-profile.store');
    Route::delete('servers/{server}/ovpn-profile', [AdminServerOperationsController::class, 'destroyOvpnProfile'])->name('servers.ovpn-profile.destroy');
    Route::post('servers/{server}/l2tp-ipsec', [AdminServerOperationsController::class, 'storeL2tpIpsec'])->name('servers.l2tp-ipsec.store');
    Route::get('servers/{server}/import-clients', [AdminServerClientImportController::class, 'create'])->name('servers.import-clients.create');
    Route::post('servers/{server}/import-clients/preview', [AdminServerClientImportController::class, 'preview'])->name('servers.import-clients.preview');
    Route::get('servers/{server}/import-clients/assign', [AdminServerClientImportController::class, 'assign'])->name('servers.import-clients.assign');
    Route::post('servers/{server}/import-clients', [AdminServerClientImportController::class, 'store'])->name('servers.import-clients.store');

    Route::resource('package-categories', AdminPackageCategoryController::class)->except(['show']);
    Route::patch('package-categories/{package_category}/toggle-active', [AdminPackageCategoryController::class, 'toggleActive'])
        ->name('package-categories.toggle-active');
    Route::get('packages/server-options/{server}', [AdminPackageController::class, 'serverProvisioningOptions'])
        ->name('packages.server-options');
    Route::resource('packages', AdminPackageController::class)->except(['show']);
    Route::patch('packages/{package}/toggle-active', [AdminPackageController::class, 'toggleActive'])
        ->name('packages.toggle-active');

    Route::get('accounts/wireguard', [AdminAccountController::class, 'indexWireguard'])->name('accounts.wireguard');
    Route::get('accounts/ppp', [AdminAccountController::class, 'indexPpp'])->name('accounts.ppp');
    Route::get('accounts/v2ray', [AdminAccountController::class, 'indexV2ray'])->name('accounts.v2ray');
    Route::get('accounts/anyconnect', [AdminAccountController::class, 'indexAnyconnect'])->name('accounts.anyconnect');
    Route::get('accounts', [AdminAccountController::class, 'index'])->name('accounts.index');
    Route::get('accounts/purchase-preview', [AdminAccountController::class, 'purchasePreview'])->name('accounts.purchase-preview');
    Route::get('accounts/client-options', [AdminAccountController::class, 'clientOptions'])->name('accounts.client-options');
    Route::get('accounts/package-options', [AdminAccountController::class, 'packageOptions'])->name('accounts.package-options');
    Route::post('accounts/kyc', [AdminAccountController::class, 'kycSubmit'])->name('accounts.kyc.submit');
    Route::post('accounts/kyc/{kycVerification}/verify', [AdminAccountController::class, 'kycVerify'])->name('accounts.kyc.verify');
    Route::post('accounts/kyc/{kycVerification}/request-reset', [AdminAccountController::class, 'kycRequestReset'])->name('accounts.kyc.request-reset');
    Route::get('accounts/create', [AdminAccountController::class, 'create'])->name('accounts.create');
    Route::get('accounts/{account}/report', [AdminAccountController::class, 'report'])->name('accounts.report');
    Route::get('accounts/{account}', [AdminAccountController::class, 'show'])->name('accounts.show');
    Route::post('accounts', [AdminAccountController::class, 'store'])->name('accounts.store');
    Route::get('accounts/{account}/edit', [AdminAccountController::class, 'edit'])->name('accounts.edit');
    Route::put('accounts/{account}', [AdminAccountController::class, 'update'])->name('accounts.update');
    Route::post('accounts/{account}/renew', [AdminAccountController::class, 'renew'])->name('accounts.renew');
    Route::post('accounts/{account}/disable', [AdminAccountController::class, 'disable'])->name('accounts.disable');
    Route::post('accounts/{account}/enable', [AdminAccountController::class, 'enable'])->name('accounts.enable');
    Route::get('accounts/{account}/portal-link', [AdminAccountController::class, 'issuePortalLink'])->name('accounts.portal-link');
    Route::get('accounts/{account}/config', [AdminAccountController::class, 'showConfig'])->name('accounts.config');
    Route::get('accounts/{account}/config/download', [AdminAccountController::class, 'downloadConfig'])->name('accounts.config.download');
    Route::get('accounts/{account}/config/qr', [AdminAccountController::class, 'downloadQr'])->name('accounts.config.qr');
    Route::get('accounts/{account}/ovpn/download', [AdminAccountController::class, 'downloadOvpnProfile'])->name('accounts.ovpn.download');
    Route::get('accounts/{account}/renew-form', [AdminAccountController::class, 'showRenew'])->name('accounts.renew-form');
    Route::get('accounts/{account}/renew-preview', [AdminAccountController::class, 'renewPreview'])->name('accounts.renew-preview');
    Route::get('accounts/{account}/transfer-form', [AdminAccountController::class, 'showTransfer'])->name('accounts.transfer-form');
    Route::get('accounts/{account}/send-login-info', [AdminAccountController::class, 'showSendLoginInfo'])->name('accounts.send-login-info-form');
    Route::post('accounts/{account}/send-login-info', [AdminAccountController::class, 'sendLoginInfo'])->name('accounts.send-login-info');
    Route::post('accounts/{account}/transfer', [AdminAccountController::class, 'storeTransfer'])->name('accounts.transfer');
    Route::post('accounts/{account}/refund', [AdminAccountController::class, 'refund'])->name('accounts.refund');
    Route::post('accounts/{account}/reactivate', [AdminAccountController::class, 'reactivate'])->name('accounts.reactivate');
    Route::delete('accounts/{account}', [AdminAccountController::class, 'destroy'])->name('accounts.destroy');

    Route::get('payment-requests', [AdminPaymentRequestController::class, 'index'])->name('payment-requests.index');
    Route::get('payment-requests/charge', [AdminPaymentRequestController::class, 'charge'])->name('payment-requests.charge');
    Route::post('payment-requests/charge', [AdminPaymentRequestController::class, 'storeCharge'])->name('payment-requests.charge.store');
    Route::post('payment-requests/bulk-charge', [AdminPaymentRequestController::class, 'storeBulkCharge'])->name('payment-requests.bulk-charge');
    Route::get('payment-requests/{paymentRequest}', [AdminPaymentRequestController::class, 'show'])->name('payment-requests.show');
    Route::post('payment-requests/{paymentRequest}/approve', [AdminPaymentRequestController::class, 'approve'])->name('payment-requests.approve');
    Route::post('payment-requests/{paymentRequest}/reject', [AdminPaymentRequestController::class, 'reject'])->name('payment-requests.reject');

    Route::resource('financial-plan-templates', AdminFinancialPlanTemplateController::class)->except(['show']);
    Route::get('agent-financial-plans', [AdminAgentFinancialPlanPurchaseController::class, 'index'])->name('agent-financial-plans.index');
    Route::get('agent-financial-plans/sell', [AdminAgentFinancialPlanPurchaseController::class, 'create'])->name('agent-financial-plans.create');
    Route::post('agent-financial-plans', [AdminAgentFinancialPlanPurchaseController::class, 'store'])->name('agent-financial-plans.store');

    Route::get('settings/gift-accounts', [AdminGiftAccountController::class, 'index'])->name('gift-accounts.index');
    Route::get('settings/gift-accounts/sellers', [AdminGiftAccountController::class, 'sellers'])->name('gift-accounts.sellers');
    Route::post('settings/gift-accounts', [AdminGiftAccountController::class, 'store'])->name('gift-accounts.store');

    Route::get('settings/gifts', [AdminGiftRewardController::class, 'index'])->name('gifts.index');
    Route::get('settings/gifts/sellers', [AdminGiftRewardController::class, 'sellers'])->name('gifts.sellers');
    Route::post('settings/gifts/days', [AdminGiftRewardController::class, 'storeDays'])->name('gifts.days');
    Route::post('settings/gifts/wallet', [AdminGiftRewardController::class, 'storeWallet'])->name('gifts.wallet');

    Route::get('settings', [AdminSettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [AdminSettingController::class, 'update'])->name('settings.update');
    Route::get('settings/logs', [AdminApplicationLogController::class, 'index'])->name('settings.logs');
    Route::post('settings/logs/clear', [AdminApplicationLogController::class, 'clear'])->name('settings.logs.clear');
    Route::get('settings/server-backups', [AdminServerBackupController::class, 'index'])->name('settings.server-backups.index');
    Route::post('settings/server-backups', [AdminServerBackupController::class, 'store'])->name('settings.server-backups.store');
    Route::get('settings/server-backups/{serverBackup}', [AdminServerBackupController::class, 'show'])->name('settings.server-backups.show');

    Route::get('modules', [AdminModuleController::class, 'index'])->name('modules.index');
    Route::post('modules/upload', [AdminModuleController::class, 'upload'])->name('modules.upload');
    Route::post('modules/{module}/activate', [AdminModuleController::class, 'activate'])->name('modules.activate');
    Route::post('modules/{module}/deactivate', [AdminModuleController::class, 'deactivate'])->name('modules.deactivate');
    Route::delete('modules/{module}', [AdminModuleController::class, 'destroy'])->name('modules.destroy');

    Route::get('sms', [AdminSmsSettingsController::class, 'index'])->name('sms.index');
    Route::put('sms', [AdminSmsSettingsController::class, 'update'])->name('sms.update');
    Route::post('sms/test', [AdminSmsSettingsController::class, 'sendTest'])->name('sms.test');

    Route::get('kyc/settings', [AdminKycSettingsController::class, 'index'])->name('kyc.settings');
    Route::put('kyc/settings', [AdminKycSettingsController::class, 'update'])->name('kyc.settings.update');
    Route::post('kyc/settings/test', [AdminKycSettingsController::class, 'test'])->name('kyc.settings.test');
    Route::get('kyc/verifications', [AdminKycVerificationController::class, 'index'])->name('kyc.index');
    Route::get('kyc/verifications/{kycVerification}', [AdminKycVerificationController::class, 'show'])->name('kyc.show');
    Route::get('kyc/verifications/{kycVerification}/document', [AdminKycVerificationController::class, 'document'])->name('kyc.document');
    Route::post('kyc/verifications/{kycVerification}/reset', [AdminKycVerificationController::class, 'reset'])->name('kyc.reset');

    Route::get('payment-gateways', [AdminPaymentGatewayController::class, 'index'])->name('payment-gateways.index');
    Route::put('payment-gateways/global', [AdminPaymentGatewayController::class, 'updateGlobal'])->name('payment-gateways.global.update');
    Route::get('payment-gateways/{driver}/edit', [AdminPaymentGatewayController::class, 'edit'])->name('payment-gateways.edit');
    Route::put('payment-gateways/{driver}', [AdminPaymentGatewayController::class, 'update'])->name('payment-gateways.update');

    Route::get('gateway-payments', [AdminGatewayPaymentController::class, 'index'])->name('gateway-payments.index');
    Route::get('gateway-payments/{gatewayPayment}', [AdminGatewayPaymentController::class, 'show'])->name('gateway-payments.show');
    Route::post('gateway-payments/{gatewayPayment}/approve', [AdminGatewayPaymentController::class, 'approve'])->name('gateway-payments.approve');
    Route::post('gateway-payments/{gatewayPayment}/reject', [AdminGatewayPaymentController::class, 'reject'])->name('gateway-payments.reject');

    Route::get('security', [AdminSecurityController::class, 'index'])->name('security.index');
    Route::put('security/paths', [AdminSecurityController::class, 'updatePaths'])->name('security.paths.update');
    Route::put('security/firewall', [AdminSecurityController::class, 'updateFirewall'])->name('security.firewall.update');
    Route::post('security/htaccess/{role}', [AdminSecurityController::class, 'applyHtaccessBasicAuth'])->name('security.htaccess.apply');
    Route::post('users/{user}/two-factor/disable', [AdminSecurityController::class, 'disableUserTwoFactor'])->name('users.two-factor.disable');

    Route::post('users/{user}/impersonate', [ImpersonationController::class, 'start'])
        ->middleware('throttle:impersonation')
        ->name('users.impersonate');
    Route::post('sellers/{user}/impersonate', [ImpersonationController::class, 'start'])
        ->middleware('throttle:impersonation')
        ->name('sellers.impersonate');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('profile/two-factor', [TwoFactorSettingsController::class, 'show'])->name('two-factor.show');
    Route::post('profile/two-factor/enable', [TwoFactorSettingsController::class, 'enable'])->name('two-factor.enable');
    Route::post('profile/two-factor/disable', [TwoFactorSettingsController::class, 'disable'])->name('two-factor.disable');


    Route::get('automation/pricing', [AdminAutomationController::class, 'pricing'])->name('automation.pricing');
    Route::put('automation/pricing', [AdminAutomationController::class, 'updatePricing'])->name('automation.pricing.update');
    Route::get('automation/portal', [\App\Http\Controllers\Admin\PortalCustomizationController::class, 'edit'])->name('automation.portal');
    Route::put('automation/portal', [\App\Http\Controllers\Admin\PortalCustomizationController::class, 'update'])->name('automation.portal.update');
    Route::get('automation', [AdminAutomationController::class, 'index'])->name('automation.index');
    Route::post('automation/cron/install', [AdminAutomationController::class, 'install'])->name('automation.install');

    Route::get('maintenance', [AdminMaintenanceController::class, 'index'])->name('maintenance.index');
    Route::put('maintenance/settings', [AdminMaintenanceController::class, 'updateSettings'])->name('maintenance.settings.update');
    Route::post('maintenance/migrate', [AdminMaintenanceController::class, 'migrate'])->name('maintenance.migrate');
    Route::post('maintenance/verify-schema', [AdminMaintenanceController::class, 'verifySchema'])->name('maintenance.verify-schema');
    Route::post('maintenance/database-backup', [AdminMaintenanceController::class, 'storeDatabaseBackup'])->name('maintenance.database-backup.store');
    Route::post('maintenance/database-backup/restore', [AdminMaintenanceController::class, 'restoreDatabaseBackup'])->name('maintenance.database-backup.restore');
    Route::get('maintenance/database-backup/download', [AdminMaintenanceController::class, 'downloadDatabaseBackup'])->name('maintenance.database-backup.download');
    Route::delete('maintenance/database-backup/{filename}', [AdminMaintenanceController::class, 'destroyDatabaseBackup'])->name('maintenance.database-backup.destroy');

    Route::get('accounting', [AdminAccountingController::class, 'index'])->name('accounting.index');
    Route::get('accounting/export', [AdminAccountingController::class, 'export'])->name('accounting.export');

    Route::get('broadcasts', [AdminBroadcastController::class, 'index'])->name('broadcasts.index');
    Route::get('broadcasts/create', [AdminBroadcastController::class, 'create'])->name('broadcasts.create');
    Route::post('broadcasts', [AdminBroadcastController::class, 'store'])->name('broadcasts.store');
    Route::post('broadcasts/{broadcast}/approve', [AdminBroadcastController::class, 'approve'])->name('broadcasts.approve');
    Route::post('broadcasts/{broadcast}/reject', [AdminBroadcastController::class, 'reject'])->name('broadcasts.reject');

    Route::get('reports', [AdminReportController::class, 'index'])->name('reports.index');

    Route::get('client-pricing', [AdminClientPortalSettingsController::class, 'editClientPricing'])->name('client-pricing.edit');
    Route::put('client-pricing', [AdminClientPortalSettingsController::class, 'updateClientPricing'])->name('client-pricing.update');
    Route::get('client-payment-card', [AdminClientPortalSettingsController::class, 'editClientPaymentCard'])->name('client-payment-card.edit');
    Route::put('client-payment-card', [AdminClientPortalSettingsController::class, 'updateClientPaymentCard'])->name('client-payment-card.update');
    Route::post('payment-cards/{paymentCard}/approve', [AdminClientPortalSettingsController::class, 'approvePaymentCard'])->name('payment-cards.approve');
    Route::post('payment-cards/{paymentCard}/reject', [AdminClientPortalSettingsController::class, 'rejectPaymentCard'])->name('payment-cards.reject');
    Route::delete('payment-cards/{paymentCard}', [AdminClientPortalSettingsController::class, 'requestDeletePaymentCard'])->name('payment-cards.destroy');

    Route::get('tickets', [AdminSupportTicketController::class, 'indexTickets'])->name('tickets.index');
    Route::get('tickets/create', [AdminSupportTicketController::class, 'createTicket'])->name('tickets.create');
    Route::post('tickets', [AdminSupportTicketController::class, 'storeTicket'])->name('tickets.store');
    Route::get('tickets/{ticket}', [AdminSupportTicketController::class, 'showTicket'])->name('tickets.show');
    Route::post('tickets/{ticket}/reply', [AdminSupportTicketController::class, 'replyTicket'])->name('tickets.reply');
    Route::put('tickets/{ticket}/status', [AdminSupportTicketController::class, 'updateTicketStatus'])->name('tickets.status');
    Route::get('support-departments', [AdminSupportTicketController::class, 'indexDepartments'])->name('tickets.departments.index');
    Route::post('support-departments', [AdminSupportTicketController::class, 'storeDepartment'])->name('tickets.departments.store');
    Route::put('support-departments/{department}', [AdminSupportTicketController::class, 'updateDepartment'])->name('tickets.departments.update');
    Route::delete('support-departments/{department}', [AdminSupportTicketController::class, 'destroyDepartment'])->name('tickets.departments.destroy');
});

Route::prefix($agentPath)->name('agent.')->middleware(['auth', 'role:agent', 'log.activity'])->group(function (): void {
    Route::get('api-tokens', [\App\Http\Controllers\Panel\ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::get('api-tokens/docs', [\App\Http\Controllers\Panel\ApiTokenController::class, 'docs'])->name('api-tokens.docs');
    Route::post('api-tokens', [\App\Http\Controllers\Panel\ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('api-tokens/{apiToken}', [\App\Http\Controllers\Panel\ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    Route::get('/', [AgentDashboardController::class, 'index'])->name('dashboard');

    Route::post('sellers/{user}/impersonate', [ImpersonationController::class, 'start'])
        ->middleware('throttle:impersonation')
        ->name('sellers.impersonate');
    Route::resource('sellers', AgentSellerController::class)->except(['show', 'destroy']);
    Route::post('sellers/{seller}', [AgentSellerController::class, 'update']);
    Route::get('clients', [AgentClientController::class, 'index'])->name('clients.index');
    Route::get('clients/create', [AgentClientController::class, 'create'])->name('clients.create');
    Route::post('clients', [AgentClientController::class, 'store'])->name('clients.store');
    Route::get('clients/{client}', [AgentClientController::class, 'show'])->name('clients.show');
    Route::get('clients/{client}/accounts/{account}', [AgentClientController::class, 'showAccount'])->name('clients.accounts.show');
    Route::post('clients/{client}/accounts/{account}/assign', [AgentClientController::class, 'assignAccount'])->name('clients.accounts.assign');
    Route::post('clients/{user}/impersonate', [ImpersonationController::class, 'start'])
        ->middleware('throttle:impersonation')
        ->name('clients.impersonate');
    Route::post('clients/accounts/{account}/reassign', [AgentClientController::class, 'reassignAccount'])->name('clients.accounts.reassign');
    Route::get('accounts/wireguard', [AgentAccountController::class, 'indexWireguard'])->name('accounts.wireguard');
    Route::get('accounts/ppp', [AgentAccountController::class, 'indexPpp'])->name('accounts.ppp');
    Route::get('accounts/v2ray', [AgentAccountController::class, 'indexV2ray'])->name('accounts.v2ray');
    Route::get('accounts/anyconnect', [AgentAccountController::class, 'indexAnyconnect'])->name('accounts.anyconnect');
    Route::get('accounts', [AgentAccountController::class, 'index'])->name('accounts.index');
    Route::get('accounts/purchase-preview', [AgentAccountController::class, 'purchasePreview'])->name('accounts.purchase-preview');
    Route::get('accounts/client-options', [AgentAccountController::class, 'clientOptions'])->name('accounts.client-options');
    Route::get('accounts/package-options', [AgentAccountController::class, 'packageOptions'])->name('accounts.package-options');
    Route::post('accounts/kyc', [AgentAccountController::class, 'kycSubmit'])->name('accounts.kyc.submit');
    Route::post('accounts/kyc/{kycVerification}/verify', [AgentAccountController::class, 'kycVerify'])->name('accounts.kyc.verify');
    Route::post('accounts/kyc/{kycVerification}/request-reset', [AgentAccountController::class, 'kycRequestReset'])->name('accounts.kyc.request-reset');
    Route::get('accounts/create', [AgentAccountController::class, 'create'])->name('accounts.create');
    Route::get('accounts/{account}/report', [AgentAccountController::class, 'report'])->name('accounts.report');
    Route::get('accounts/{account}', [AgentAccountController::class, 'show'])->name('accounts.show');
    Route::resource('accounts', AgentAccountController::class)->except(['show', 'index', 'destroy', 'create']);
    Route::post('accounts/{account}/renew', [AgentAccountController::class, 'renew'])->name('accounts.renew');
    Route::post('accounts/{account}/disable', [AgentAccountController::class, 'disable'])->name('accounts.disable');
    Route::post('accounts/{account}/enable', [AgentAccountController::class, 'enable'])->name('accounts.enable');
    Route::get('accounts/{account}/portal-link', [AgentAccountController::class, 'issuePortalLink'])->name('accounts.portal-link');
    Route::get('accounts/{account}/config', [AgentAccountController::class, 'showConfig'])->name('accounts.config');
    Route::get('accounts/{account}/config/download', [AgentAccountController::class, 'downloadConfig'])->name('accounts.config.download');
    Route::get('accounts/{account}/config/qr', [AgentAccountController::class, 'downloadQr'])->name('accounts.config.qr');
    Route::get('accounts/{account}/ovpn/download', [AgentAccountController::class, 'downloadOvpnProfile'])->name('accounts.ovpn.download');
    Route::get('accounts/{account}/renew-form', [AgentAccountController::class, 'showRenew'])->name('accounts.renew-form');
    Route::get('accounts/{account}/renew-preview', [AgentAccountController::class, 'renewPreview'])->name('accounts.renew-preview');
    Route::get('accounts/{account}/transfer-form', [AgentAccountController::class, 'showTransfer'])->name('accounts.transfer-form');
    Route::get('accounts/{account}/send-login-info', [AgentAccountController::class, 'showSendLoginInfo'])->name('accounts.send-login-info-form');
    Route::post('accounts/{account}/send-login-info', [AgentAccountController::class, 'sendLoginInfo'])->name('accounts.send-login-info');
    Route::post('accounts/{account}/transfer', [AgentAccountController::class, 'storeTransfer'])->name('accounts.transfer');
    Route::post('accounts/{account}/refund', [AgentAccountController::class, 'refund'])->name('accounts.refund');

    Route::get('payment-requests', [AgentPaymentRequestController::class, 'index'])->name('payment-requests.index');
    Route::get('financial-plans', [AgentFinancialPlanController::class, 'index'])->name('financial-plans.index');
    Route::get('payment-requests/create', [AgentPaymentRequestController::class, 'create'])->name('payment-requests.create');
    Route::post('payment-requests', [AgentPaymentRequestController::class, 'store'])->name('payment-requests.store');
    Route::get('payment-requests/{paymentRequest}', [AgentPaymentRequestController::class, 'show'])->name('payment-requests.show');
    Route::post('payment-requests/{paymentRequest}/approve', [AgentPaymentRequestController::class, 'approve'])->name('payment-requests.approve');
    Route::post('payment-requests/{paymentRequest}/reject', [AgentPaymentRequestController::class, 'reject'])->name('payment-requests.reject');

    Route::get('wallet/top-up', [GatewayTopUpController::class, 'create'])->name('wallet.top-up.create');
    Route::post('wallet/top-up', [GatewayTopUpController::class, 'store'])->name('wallet.top-up.store');
    Route::post('wallet/top-up/preview', [GatewayTopUpController::class, 'preview'])->name('wallet.top-up.preview');
    Route::get('wallet/top-up/{gatewayPayment}', [GatewayTopUpController::class, 'show'])->name('wallet.top-up.show');
    Route::get('wallet/top-up/{gatewayPayment}/return', [GatewayTopUpController::class, 'return'])->name('wallet.top-up.return');

    Route::get('accounting', [AgentAccountingController::class, 'index'])->name('accounting.index');
    Route::get('accounting/corrections', [AgentAccountingCorrectionController::class, 'index'])->name('accounting.corrections');
    Route::get('accounting/export', [AgentAccountingController::class, 'export'])->name('accounting.export');

    Route::get('broadcasts', [AgentBroadcastController::class, 'index'])->name('broadcasts.index');
    Route::get('broadcasts/create', [AgentBroadcastController::class, 'create'])->name('broadcasts.create');
    Route::post('broadcasts', [AgentBroadcastController::class, 'store'])->name('broadcasts.store');

    Route::get('storefront', [AgentStorefrontController::class, 'edit'])->name('storefront.edit');
    Route::put('storefront', [AgentStorefrontController::class, 'update'])->name('storefront.update');

    Route::get('invoices', [AgentInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [AgentInvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [AgentInvoiceController::class, 'pdf'])->name('invoices.pdf');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('profile/two-factor', [TwoFactorSettingsController::class, 'show'])->name('two-factor.show');
    Route::post('profile/two-factor/enable', [TwoFactorSettingsController::class, 'enable'])->name('two-factor.enable');
    Route::post('profile/two-factor/disable', [TwoFactorSettingsController::class, 'disable'])->name('two-factor.disable');

    Route::get('client-pricing', [AgentClientPortalSettingsController::class, 'editClientPricing'])->name('client-pricing.edit');
    Route::put('client-pricing', [AgentClientPortalSettingsController::class, 'updateClientPricing'])->name('client-pricing.update');
    Route::get('client-payment-card', [AgentClientPortalSettingsController::class, 'editClientPaymentCard'])->name('client-payment-card.edit');
    Route::put('client-payment-card', [AgentClientPortalSettingsController::class, 'updateClientPaymentCard'])->name('client-payment-card.update');
    Route::post('payment-cards/{paymentCard}/approve', [AgentClientPortalSettingsController::class, 'approvePaymentCard'])->name('payment-cards.approve');
    Route::post('payment-cards/{paymentCard}/reject', [AgentClientPortalSettingsController::class, 'rejectPaymentCard'])->name('payment-cards.reject');
    Route::delete('payment-cards/{paymentCard}', [AgentClientPortalSettingsController::class, 'requestDeletePaymentCard'])->name('payment-cards.destroy');

    Route::get('tickets', [AgentSupportTicketController::class, 'indexTickets'])->name('tickets.index');
    Route::get('tickets/create', [AgentSupportTicketController::class, 'createTicket'])->name('tickets.create');
    Route::post('tickets', [AgentSupportTicketController::class, 'storeTicket'])->name('tickets.store');
    Route::get('tickets/{ticket}', [AgentSupportTicketController::class, 'showTicket'])->name('tickets.show');
    Route::post('tickets/{ticket}/reply', [AgentSupportTicketController::class, 'replyTicket'])->name('tickets.reply');
    Route::put('tickets/{ticket}/status', [AgentSupportTicketController::class, 'updateTicketStatus'])->name('tickets.status');
    Route::post('tickets/{ticket}/escalate', [AgentSupportTicketController::class, 'escalateTicket'])->name('tickets.escalate');
    Route::get('support-departments', [AgentSupportTicketController::class, 'indexDepartments'])->name('tickets.departments.index');
    Route::post('support-departments', [AgentSupportTicketController::class, 'storeDepartment'])->name('tickets.departments.store');
    Route::put('support-departments/{department}', [AgentSupportTicketController::class, 'updateDepartment'])->name('tickets.departments.update');
    Route::delete('support-departments/{department}', [AgentSupportTicketController::class, 'destroyDepartment'])->name('tickets.departments.destroy');
});

Route::prefix($sellerPath)->name('seller.')->middleware(['auth', 'role:seller', 'log.activity'])->group(function (): void {
    Route::get('api-tokens', [\App\Http\Controllers\Panel\ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::get('api-tokens/docs', [\App\Http\Controllers\Panel\ApiTokenController::class, 'docs'])->name('api-tokens.docs');
    Route::post('api-tokens', [\App\Http\Controllers\Panel\ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('api-tokens/{apiToken}', [\App\Http\Controllers\Panel\ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    Route::get('/', [SellerDashboardController::class, 'index'])->name('dashboard');

    Route::get('clients', [SellerClientController::class, 'index'])->name('clients.index');
    Route::get('clients/create', [SellerClientController::class, 'create'])->name('clients.create');
    Route::post('clients', [SellerClientController::class, 'store'])->name('clients.store');
    Route::get('clients/{client}', [SellerClientController::class, 'show'])->name('clients.show');
    Route::get('clients/{client}/accounts/{account}', [SellerClientController::class, 'showAccount'])->name('clients.accounts.show');
    Route::post('clients/{client}/accounts/{account}/assign', [SellerClientController::class, 'assignAccount'])->name('clients.accounts.assign');
    Route::post('clients/{user}/impersonate', [ImpersonationController::class, 'start'])
        ->middleware('throttle:impersonation')
        ->name('clients.impersonate');

    Route::get('accounts/wireguard', [SellerAccountController::class, 'indexWireguard'])->name('accounts.wireguard');
    Route::get('accounts/ppp', [SellerAccountController::class, 'indexPpp'])->name('accounts.ppp');
    Route::get('accounts/v2ray', [SellerAccountController::class, 'indexV2ray'])->name('accounts.v2ray');
    Route::get('accounts/anyconnect', [SellerAccountController::class, 'indexAnyconnect'])->name('accounts.anyconnect');
    Route::get('accounts', [SellerAccountController::class, 'index'])->name('accounts.index');
    Route::get('accounts/purchase-preview', [SellerAccountController::class, 'purchasePreview'])->name('accounts.purchase-preview');
    Route::get('accounts/client-options', [SellerAccountController::class, 'clientOptions'])->name('accounts.client-options');
    Route::get('accounts/package-options', [SellerAccountController::class, 'packageOptions'])->name('accounts.package-options');
    Route::post('accounts/kyc', [SellerAccountController::class, 'kycSubmit'])->name('accounts.kyc.submit');
    Route::post('accounts/kyc/{kycVerification}/verify', [SellerAccountController::class, 'kycVerify'])->name('accounts.kyc.verify');
    Route::post('accounts/kyc/{kycVerification}/request-reset', [SellerAccountController::class, 'kycRequestReset'])->name('accounts.kyc.request-reset');
    Route::get('accounts/create', [SellerAccountController::class, 'create'])->name('accounts.create');
    Route::get('accounts/{account}/report', [SellerAccountController::class, 'report'])->name('accounts.report');
    Route::get('accounts/{account}', [SellerAccountController::class, 'show'])->name('accounts.show');
    Route::resource('accounts', SellerAccountController::class)->except(['show', 'index', 'destroy', 'create']);
    Route::post('accounts/{account}/renew', [SellerAccountController::class, 'renew'])->name('accounts.renew');
    Route::post('accounts/{account}/disable', [SellerAccountController::class, 'disable'])->name('accounts.disable');
    Route::post('accounts/{account}/enable', [SellerAccountController::class, 'enable'])->name('accounts.enable');
    Route::post('accounts/{account}/refund', [SellerAccountController::class, 'refund'])->name('accounts.refund');
    Route::get('accounts/{account}/portal-link', [SellerAccountController::class, 'issuePortalLink'])->name('accounts.portal-link');
    Route::get('accounts/{account}/config', [SellerAccountController::class, 'showConfig'])->name('accounts.config');
    Route::get('accounts/{account}/config/download', [SellerAccountController::class, 'downloadConfig'])->name('accounts.config.download');
    Route::get('accounts/{account}/config/qr', [SellerAccountController::class, 'downloadQr'])->name('accounts.config.qr');
    Route::get('accounts/{account}/ovpn/download', [SellerAccountController::class, 'downloadOvpnProfile'])->name('accounts.ovpn.download');
    Route::get('accounts/{account}/renew-form', [SellerAccountController::class, 'showRenew'])->name('accounts.renew-form');
    Route::get('accounts/{account}/renew-preview', [SellerAccountController::class, 'renewPreview'])->name('accounts.renew-preview');
    Route::get('accounts/{account}/send-login-info', [SellerAccountController::class, 'showSendLoginInfo'])->name('accounts.send-login-info-form');
    Route::post('accounts/{account}/send-login-info', [SellerAccountController::class, 'sendLoginInfo'])->name('accounts.send-login-info');

    Route::get('payment-requests', [SellerPaymentRequestController::class, 'index'])->name('payment-requests.index');
    Route::get('payment-requests/create', [SellerPaymentRequestController::class, 'create'])->name('payment-requests.create');
    Route::post('payment-requests', [SellerPaymentRequestController::class, 'store'])->name('payment-requests.store');
    Route::get('payment-requests/{paymentRequest}', [SellerPaymentRequestController::class, 'show'])->name('payment-requests.show');
    Route::post('payment-requests/{paymentRequest}/approve', [SellerPaymentRequestController::class, 'approve'])->name('payment-requests.approve');
    Route::post('payment-requests/{paymentRequest}/reject', [SellerPaymentRequestController::class, 'reject'])->name('payment-requests.reject');

    Route::get('wallet/top-up', [GatewayTopUpController::class, 'create'])->name('wallet.top-up.create');
    Route::post('wallet/top-up', [GatewayTopUpController::class, 'store'])->name('wallet.top-up.store');
    Route::post('wallet/top-up/preview', [GatewayTopUpController::class, 'preview'])->name('wallet.top-up.preview');
    Route::get('wallet/top-up/{gatewayPayment}', [GatewayTopUpController::class, 'show'])->name('wallet.top-up.show');
    Route::get('wallet/top-up/{gatewayPayment}/return', [GatewayTopUpController::class, 'return'])->name('wallet.top-up.return');

    Route::get('storefront', [SellerStorefrontController::class, 'edit'])->name('storefront.edit');
    Route::put('storefront', [SellerStorefrontController::class, 'update'])->name('storefront.update');

    Route::get('invoices', [SellerInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [SellerInvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [SellerInvoiceController::class, 'pdf'])->name('invoices.pdf');

    Route::get('accounting', [SellerAccountingController::class, 'index'])->name('accounting.index');
    Route::get('accounting/export', [SellerAccountingController::class, 'export'])->name('accounting.export');

    Route::get('transactions', [SellerTransactionController::class, 'index'])->name('transactions.index');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('profile/two-factor', [TwoFactorSettingsController::class, 'show'])->name('two-factor.show');
    Route::post('profile/two-factor/enable', [TwoFactorSettingsController::class, 'enable'])->name('two-factor.enable');
    Route::post('profile/two-factor/disable', [TwoFactorSettingsController::class, 'disable'])->name('two-factor.disable');

    Route::get('client-pricing', [SellerClientPortalSettingsController::class, 'editClientPricing'])->name('client-pricing.edit');
    Route::put('client-pricing', [SellerClientPortalSettingsController::class, 'updateClientPricing'])->name('client-pricing.update');
    Route::get('client-payment-card', [SellerClientPortalSettingsController::class, 'editClientPaymentCard'])->name('client-payment-card.edit');
    Route::put('client-payment-card', [SellerClientPortalSettingsController::class, 'updateClientPaymentCard'])->name('client-payment-card.update');
    Route::delete('payment-cards/{paymentCard}', [SellerClientPortalSettingsController::class, 'requestDeletePaymentCard'])->name('payment-cards.destroy');

    Route::get('tickets', [SellerSupportTicketController::class, 'indexTickets'])->name('tickets.index');
    Route::get('tickets/create', [SellerSupportTicketController::class, 'createTicket'])->name('tickets.create');
    Route::post('tickets', [SellerSupportTicketController::class, 'storeTicket'])->name('tickets.store');
    Route::get('tickets/{ticket}', [SellerSupportTicketController::class, 'showTicket'])->name('tickets.show');
    Route::post('tickets/{ticket}/reply', [SellerSupportTicketController::class, 'replyTicket'])->name('tickets.reply');
    Route::put('tickets/{ticket}/status', [SellerSupportTicketController::class, 'updateTicketStatus'])->name('tickets.status');
});

Route::prefix('client')->name('client.')->middleware(['auth', 'role:client', 'log.activity', 'throttle:client-panel'])->group(function (): void {
    Route::get('/', [ClientDashboardController::class, 'index'])->name('dashboard');
    Route::get('shop', [ClientShopController::class, 'index'])->name('shop.index');
    Route::post('shop', [ClientShopController::class, 'store'])->middleware('throttle:client-actions')->name('shop.store');
    Route::get('accounts', [ClientAccountController::class, 'index'])->name('accounts.index');
    Route::get('accounts/{account}', [ClientAccountController::class, 'show'])->name('accounts.show');
    Route::get('accounts/{account}/ovpn/download', [ClientAccountController::class, 'downloadOvpnProfile'])->name('accounts.ovpn.download');
    Route::get('accounts/{account}/stats', [ClientAccountController::class, 'stats'])->name('accounts.stats');
    Route::post('accounts/{account}/renew', [ClientAccountController::class, 'renew'])->middleware('throttle:client-actions')->name('accounts.renew');
    Route::get('payment-requests', [ClientPaymentRequestController::class, 'index'])->name('payment-requests.index');
    Route::get('payment-requests/create', [ClientPaymentRequestController::class, 'create'])->name('payment-requests.create');
    Route::post('payment-requests', [ClientPaymentRequestController::class, 'store'])->middleware('throttle:client-actions')->name('payment-requests.store');

    Route::get('wallet/top-up', [GatewayTopUpController::class, 'create'])->name('wallet.top-up.create');
    Route::post('wallet/top-up', [GatewayTopUpController::class, 'store'])->middleware('throttle:client-actions')->name('wallet.top-up.store');
    Route::post('wallet/top-up/preview', [GatewayTopUpController::class, 'preview'])->middleware('throttle:client-actions')->name('wallet.top-up.preview');
    Route::get('wallet/top-up/{gatewayPayment}', [GatewayTopUpController::class, 'show'])->name('wallet.top-up.show');
    Route::get('wallet/top-up/{gatewayPayment}/return', [GatewayTopUpController::class, 'return'])->name('wallet.top-up.return');
});

Route::middleware(['auth', 'log.activity'])->group(function (): void {
    Route::post('broadcast-banner/dismiss', [BroadcastBannerController::class, 'dismiss'])->name('broadcast-banner.dismiss');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
});
