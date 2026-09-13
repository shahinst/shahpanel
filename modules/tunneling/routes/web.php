<?php

use App\Support\PortalPaths;
use Illuminate\Support\Facades\Route;
use Modules\Tunneling\Http\Controllers\Admin\TunnelGroupActionController as AdminTunnelGroupActionController;
use Modules\Tunneling\Http\Controllers\Admin\TunnelGroupController as AdminTunnelGroupController;
use Modules\Tunneling\Http\Controllers\Admin\TunnelingMetricsController as AdminTunnelingMetricsController;
use Modules\Tunneling\Http\Controllers\Admin\TunnelLocationController as AdminTunnelLocationController;
use Modules\Tunneling\Http\Controllers\Admin\TunnelWizardController as AdminTunnelWizardController;
use Modules\Tunneling\Http\Controllers\TunnelReportController;

/*
| The whole tunneling section. Loaded only while the module is active, so
| deactivating it removes every one of these routes from the panel.
|
| The admin group is rebuilt here exactly as routes/web.php builds it, because
| module routes are registered separately from the core route file.
*/

// Probe reports POSTed by the on-router vpnl-probe script (token-authenticated, CSRF-exempt).
Route::post('/tunneling/report', [TunnelReportController::class, 'store'])
    ->middleware('throttle:240,1')
    ->name('tunneling.report');

$adminMiddleware = ['auth', 'role:admin', 'log.activity'];
if (class_exists(\App\Http\Middleware\RestrictAdminByIp::class)) {
    $adminMiddleware[] = 'admin.ip';
}

Route::prefix(PortalPaths::slug('admin'))->name('admin.')->middleware($adminMiddleware)->group(function (): void {
    Route::prefix('tunneling')->name('tunneling.')->group(function (): void {
        Route::get('/', [AdminTunnelGroupController::class, 'index'])->name('index');

        Route::prefix('wizard')->name('wizard.')->group(function (): void {
            Route::get('step1', [AdminTunnelWizardController::class, 'step1'])->name('step1');
            Route::post('step1', [AdminTunnelWizardController::class, 'step1Store'])->name('step1.store');
            Route::get('step2', [AdminTunnelWizardController::class, 'step2'])->name('step2');
            Route::post('step2', [AdminTunnelWizardController::class, 'step2Store'])->name('step2.store');
            Route::get('step3', [AdminTunnelWizardController::class, 'step3'])->name('step3');
            Route::post('step3', [AdminTunnelWizardController::class, 'step3Store'])->name('step3.store');
            Route::get('step4', [AdminTunnelWizardController::class, 'step4'])->name('step4');
            Route::post('step4', [AdminTunnelWizardController::class, 'step4Store'])->name('step4.store');
            Route::get('step5', [AdminTunnelWizardController::class, 'step5'])->name('step5');
            Route::post('finish', [AdminTunnelWizardController::class, 'finish'])->name('finish');
            Route::post('restart', [AdminTunnelWizardController::class, 'restart'])->name('restart');
        });

        Route::get('groups/create', [AdminTunnelGroupController::class, 'create'])->name('groups.create');
        Route::post('groups', [AdminTunnelGroupController::class, 'store'])->name('groups.store');
        Route::get('groups/{group}', [AdminTunnelGroupController::class, 'show'])->name('groups.show');
        Route::get('groups/{group}/edit', [AdminTunnelGroupController::class, 'edit'])->name('groups.edit');
        Route::put('groups/{group}', [AdminTunnelGroupController::class, 'update'])->name('groups.update');
        Route::delete('groups/{group}', [AdminTunnelGroupController::class, 'destroy'])->name('groups.destroy');
        Route::post('groups/{group}/delete', [AdminTunnelGroupController::class, 'destroy'])->name('groups.delete');

        Route::post('groups/{group}/configure-test', [AdminTunnelGroupActionController::class, 'configureAndTest'])->name('groups.configure-test');
        Route::post('groups/{group}/wipe-routers', [AdminTunnelGroupActionController::class, 'wipeRouters'])->name('groups.wipe-routers');
        Route::post('groups/{group}/wipe-and-apply', [AdminTunnelGroupActionController::class, 'wipeAndApply'])->name('groups.wipe-and-apply');
        Route::post('groups/{group}/reverse', [AdminTunnelGroupActionController::class, 'reverse'])->name('groups.reverse');
        Route::post('groups/{group}/promote-kind', [AdminTunnelGroupActionController::class, 'promoteKind'])->name('groups.promote-kind');
        Route::post('groups/{group}/reconcile', [AdminTunnelGroupActionController::class, 'reconcile'])->name('groups.reconcile');
        Route::post('groups/{group}/probe-mtu', [AdminTunnelGroupActionController::class, 'probeMtu'])->name('groups.probe-mtu');
        Route::post('groups/{group}/traffic-test', [AdminTunnelGroupActionController::class, 'trafficTest'])->name('groups.traffic-test');
        Route::post('groups/{group}/rollback/{version}', [AdminTunnelGroupActionController::class, 'rollback'])->name('groups.rollback');

        Route::post('agents/{agent}/toggle', [AdminTunnelGroupActionController::class, 'toggleAgent'])->name('agents.toggle');
        Route::post('agents/{agent}/weight', [AdminTunnelGroupActionController::class, 'setAgentWeight'])->name('agents.weight');
        Route::post('agents/{agent}/switch', [AdminTunnelGroupActionController::class, 'switchAgent'])->name('agents.switch');

        Route::post('servers/{server}/install-script', [AdminTunnelGroupActionController::class, 'installScript'])->name('servers.install-script');

        Route::post('interfaces', [AdminTunnelGroupActionController::class, 'storeInterface'])->name('interfaces.store');
        Route::delete('interfaces/{interface}', [AdminTunnelGroupActionController::class, 'destroyInterface'])->name('interfaces.destroy');

        Route::get('locations', [AdminTunnelLocationController::class, 'index'])->name('locations.index');
        Route::post('locations', [AdminTunnelLocationController::class, 'store'])->name('locations.store');
        Route::put('locations/{location}', [AdminTunnelLocationController::class, 'update'])->name('locations.update');
        Route::delete('locations/{location}', [AdminTunnelLocationController::class, 'destroy'])->name('locations.destroy');

        Route::get('groups/{group}/metrics', [AdminTunnelingMetricsController::class, 'group'])->name('groups.metrics');
        Route::get('groups/{group}/status', [AdminTunnelingMetricsController::class, 'groupStatus'])->name('groups.status');
        Route::get('groups/{group}/server-metrics', [AdminTunnelingMetricsController::class, 'servers'])->name('groups.server-metrics');
    });
});
