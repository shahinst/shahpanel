<?php

use App\Support\PortalPaths;
use Illuminate\Support\Facades\Route;
use Modules\Transfer\Http\Controllers\Admin\TransferController;

/*
| The panel transfer section. Loaded only while the module is active. The
| admin group is rebuilt here exactly as routes/web.php builds it; "transfer.*"
| is a main-admin-only section in config/admin_sections.php.
*/

$adminMiddleware = ['auth', 'role:admin', 'log.activity'];
if (class_exists(\App\Http\Middleware\RestrictAdminByIp::class)) {
    $adminMiddleware[] = 'admin.ip';
}
$adminMiddleware[] = 'admin.section';

Route::prefix(PortalPaths::slug('admin'))->name('admin.')->middleware($adminMiddleware)->group(function (): void {
    Route::get('transfer', [TransferController::class, 'index'])->name('transfer.index');
    Route::post('transfer/chunk', [TransferController::class, 'chunk'])->name('transfer.chunk');
    Route::post('transfer/start', [TransferController::class, 'start'])->name('transfer.start');
});
