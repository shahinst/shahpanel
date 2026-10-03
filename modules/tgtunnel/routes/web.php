<?php

use App\Http\Middleware\RestrictAdminByIp;
use App\Support\PortalPaths;
use Illuminate\Support\Facades\Route;
use Modules\TgTunnel\Http\Controllers\TunnelController;

// Rebuilt exactly as the core admin group, so the section gate, the IP
// allowlist and the activity log apply. The section config keeps these to the
// main admin: this changes the server's routing as root.
$adminMiddleware = ['auth', 'role:admin', 'log.activity'];
if (class_exists(RestrictAdminByIp::class)) {
    $adminMiddleware[] = 'admin.ip';
}
$adminMiddleware[] = 'admin.section';

Route::prefix(PortalPaths::slug('admin'))->name('admin.')->middleware($adminMiddleware)->group(function (): void {
    Route::get('telegram-tunnel', [TunnelController::class, 'index'])->name('tgtunnel.index');
    Route::post('telegram-tunnel', [TunnelController::class, 'save'])->name('tgtunnel.save');
    Route::post('telegram-tunnel/test', [TunnelController::class, 'test'])->name('tgtunnel.test');
    Route::post('telegram-tunnel/disconnect', [TunnelController::class, 'disconnect'])->name('tgtunnel.disconnect');
});
