<?php

use App\Support\PortalPaths;
use Illuminate\Support\Facades\Route;
use Modules\Watchdog\Http\Controllers\Admin\WatchdogController;

/*
| The watchdog page. Loaded only while the module is active; the admin group is
| rebuilt here exactly as routes/web.php builds it.
*/

$adminMiddleware = ['auth', 'role:admin', 'log.activity'];
if (class_exists(\App\Http\Middleware\RestrictAdminByIp::class)) {
    $adminMiddleware[] = 'admin.ip';
}
$adminMiddleware[] = 'admin.section';

Route::prefix(PortalPaths::slug('admin'))->name('admin.')->middleware($adminMiddleware)->group(function (): void {
    Route::get('watchdog', [WatchdogController::class, 'index'])->name('watchdog.index');
    Route::post('watchdog', [WatchdogController::class, 'update'])->name('watchdog.update');
    Route::post('watchdog/run', [WatchdogController::class, 'run'])->name('watchdog.run');
});
