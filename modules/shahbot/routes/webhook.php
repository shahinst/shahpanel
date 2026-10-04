<?php

use Illuminate\Support\Facades\Route;
use Modules\ShahBot\Http\Controllers\MiniAppController;
use Modules\ShahBot\Http\Controllers\PaymentReturnController;
use Modules\ShahBot\Http\Controllers\WebhookController;

Route::post('/shahbot/webhook/{secret}', WebhookController::class)
    ->where('secret', '[A-Za-z0-9]{20,100}')
    ->name('shahbot.webhook');

Route::get('/shahbot/pay/{uuid}', PaymentReturnController::class)
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->name('shahbot.pay.return');

// Telegram Mini App: a public page; its data needs Telegram's signed initData.
Route::get('/shahbot/app/{bot}', [MiniAppController::class, 'show'])
    ->whereNumber('bot')
    ->name('shahbot.app');
Route::post('/shahbot/app/{bot}/me', [MiniAppController::class, 'me'])
    ->whereNumber('bot')
    ->middleware('throttle:60,1')
    ->name('shahbot.app.me');

// A reseller's own NowPayments callback (one URL per bot, shown on "My bot").
Route::post('/shahbot/np/{bot}/{secret}', \Modules\ShahBot\Http\Controllers\ResellerIpnController::class)
    ->whereNumber('bot')
    ->where('secret', '[A-Za-z0-9]{20,100}')
    ->middleware('throttle:120,1')
    ->name('shahbot.np.ipn');
