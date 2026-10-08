<?php

use App\Support\PortalPaths;
use Illuminate\Support\Facades\Route;
use Modules\Webhooks\Http\Controllers\Admin\WebhooksController;

/*
| The event webhooks page. Loaded only while the module is active; the admin
| group is rebuilt here exactly as routes/web.php builds it.
*/

$adminMiddleware = ['auth', 'role:admin', 'log.activity'];
if (class_exists(\App\Http\Middleware\RestrictAdminByIp::class)) {
    $adminMiddleware[] = 'admin.ip';
}
$adminMiddleware[] = 'admin.section';

Route::prefix(PortalPaths::slug('admin'))->name('admin.')->middleware($adminMiddleware)->group(function (): void {
    Route::get('webhooks', [WebhooksController::class, 'index'])->name('webhooks.index');
    Route::post('webhooks', [WebhooksController::class, 'update'])->name('webhooks.update');
    Route::post('webhooks/test', [WebhooksController::class, 'test'])->name('webhooks.test');
});
