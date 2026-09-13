<?php

use App\Http\Controllers\ZarinpalWebhookController;
use Illuminate\Support\Facades\Route;
use Modules\NowPayments\Http\NowPaymentsWebhookController;

// Payment webhooks. Registered only while the payments module is active — when
// the module is deactivated these routes disappear entirely (return 404).
// CSRF is exempted for these URIs in bootstrap/app.php.
Route::middleware(['web', 'throttle:120,1'])
    ->post('/webhooks/nowpayments', [NowPaymentsWebhookController::class, 'handle'])
    ->name('webhooks.nowpayments');

Route::middleware(['web', 'throttle:120,1'])
    ->match(['get', 'post'], '/webhooks/zarinpal', [ZarinpalWebhookController::class, 'handle'])
    ->name('webhooks.zarinpal');
