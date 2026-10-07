<?php

use Illuminate\Support\Facades\Route;
use Modules\Transfer\Http\Controllers\Admin\TransferController;

// Also exempt from maintenance mode in bootstrap/app.php.
Route::get('panel-transfer/status/{job}', [TransferController::class, 'status'])
    ->where('job', '[A-Za-z0-9]{40}')
    ->name('transfer.status');
