<?php

use App\Http\Middleware\RestrictAdminByIp;
use App\Support\PortalPaths;
use Illuminate\Support\Facades\Route;
use Modules\Markup\Http\Controllers\MarkupController;

// Rebuilt exactly as the core admin group, so the section gate, the IP
// allowlist and the activity log apply here too.
$adminMiddleware = ['auth', 'role:admin', 'log.activity'];
if (class_exists(RestrictAdminByIp::class)) {
    $adminMiddleware[] = 'admin.ip';
}
$adminMiddleware[] = 'admin.section';

Route::prefix(PortalPaths::slug('admin'))->name('admin.')->middleware($adminMiddleware)->group(function (): void {
    Route::get('markup', [MarkupController::class, 'admin'])->name('markup.index');
    Route::post('markup', [MarkupController::class, 'saveMax'])->name('markup.update');
});

Route::prefix(PortalPaths::slug('agent'))->name('agent.')->middleware(['auth', 'role:agent', 'log.activity'])->group(function (): void {
    Route::get('markup', [MarkupController::class, 'agent'])->name('markup.index');
    Route::post('markup', [MarkupController::class, 'saveAgent'])->name('markup.update');
});
