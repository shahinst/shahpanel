<?php

use Illuminate\Support\Facades\Route;
use Modules\Tunneling\Http\Controllers\Api\CrmTunnelController;
use Modules\Tunneling\Http\Controllers\Api\CrmTunnelServerController;

// The same guards as the module's admin pages (routes/web.php): without the
// section gate, the admin IP allowlist and the activity log, an admin who was
// not given the tunneling section -- or who connects from an IP outside the
// allowlist -- could still create, redeploy or delete tunnels here unlogged.
// The names put every route in the tunneling section (admin_sections.php).
$middleware = ['web', 'auth', 'role:admin', 'log.activity'];
if (class_exists(\App\Http\Middleware\RestrictAdminByIp::class)) {
    $middleware[] = 'admin.ip';
}
$middleware[] = 'admin.section';

Route::middleware($middleware)->name('admin.tunneling.api.')->group(function (): void {
    Route::get('servers', [CrmTunnelServerController::class, 'index'])->name('servers.index');
    Route::patch('servers/{server}', [CrmTunnelServerController::class, 'update'])->name('servers.update');
    Route::post('servers/{server}/test-connection', [CrmTunnelServerController::class, 'testConnection'])->name('servers.test');

    Route::get('tunnels', [CrmTunnelController::class, 'index'])->name('tunnels.index');
    Route::post('tunnels', [CrmTunnelController::class, 'store'])->name('tunnels.store');
    Route::get('tunnels/{tunnel}', [CrmTunnelController::class, 'show'])->name('tunnels.show');
    Route::post('tunnels/{tunnel}/redeploy', [CrmTunnelController::class, 'redeploy'])->name('tunnels.redeploy');
    Route::post('tunnels/{tunnel}/test', [CrmTunnelController::class, 'test'])->name('tunnels.test');
    Route::delete('tunnels/{tunnel}', [CrmTunnelController::class, 'destroy'])->name('tunnels.destroy');
    Route::get('tunnels/{tunnel}/logs', [CrmTunnelController::class, 'logs'])->name('tunnels.logs');
});
