<?php

use Illuminate\Support\Facades\Route;
use Modules\ShahBot\Http\Controllers\WebhookController;

Route::post('/shahbot/webhook/{secret}', WebhookController::class)
    ->where('secret', '[A-Za-z0-9]{20,100}')
    ->name('shahbot.webhook');
