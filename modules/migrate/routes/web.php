<?php

use App\Support\PortalPaths;
use Illuminate\Support\Facades\Route;
use Modules\Migrate\Http\Controllers\Admin\MigrateController as AdminMigrateController;

/*
| The Sanaei → Remnawave migration section. Loaded only while the module is
| active, so deactivating it removes these routes from the panel.
|
| The admin group is rebuilt here exactly as routes/web.php builds it, because
| module routes are registered separately from the core route file.
*/

$adminMiddleware = ['auth', 'role:admin', 'log.activity'];
if (class_exists(\App\Http\Middleware\RestrictAdminByIp::class)) {
    $adminMiddleware[] = 'admin.ip';
}
// همان چیدمانی که routes/web.php دارد: دسترسی بخش‌به‌بخش باید روی مسیرهای
// ماژول هم اعمال شود، وگرنه یک ادمینِ محدودشده می‌توانست همین بخش را باز کند.
$adminMiddleware[] = 'admin.section';

Route::prefix(PortalPaths::slug('admin'))->name('admin.')->middleware($adminMiddleware)->group(function (): void {
    Route::get('migrate', [AdminMigrateController::class, 'index'])->name('migrate.index');
    Route::get('migrate/logs/{migration}', [AdminMigrateController::class, 'show'])->name('migrate.show');
    Route::post('migrate', [AdminMigrateController::class, 'store'])->name('migrate.store');
    Route::get('migrate/entries/{entry}/qr', [AdminMigrateController::class, 'qr'])->name('migrate.entry.qr');
});
