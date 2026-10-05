<?php

use App\Http\Controllers\Admin\InboundAllocationController;
use App\Http\Controllers\Agent\InboundController;
use App\Http\Middleware\RestrictAdminByIp;
use App\Support\PortalPaths;
use Illuminate\Support\Facades\Route;
use Modules\Dedicated\Http\Controllers\AdminController;
use Modules\Dedicated\Http\Controllers\AgentController;

// Rebuilt exactly as the core admin group, so the section gate, the IP
// allowlist and the activity log apply to everything below.
$adminMiddleware = ['auth', 'role:admin', 'log.activity'];
if (class_exists(RestrictAdminByIp::class)) {
    $adminMiddleware[] = 'admin.ip';
}
$adminMiddleware[] = 'admin.section';

Route::prefix(PortalPaths::slug('admin'))->name('admin.')->middleware($adminMiddleware)->group(function (): void {
    Route::get('dedicated-agents', [AdminController::class, 'index'])->name('dedicated.index');
    Route::post('dedicated-agents', [AdminController::class, 'store'])->name('dedicated.store');
    Route::put('dedicated-agents/{dedicated}', [AdminController::class, 'update'])->name('dedicated.update');
    Route::post('dedicated-agents/{dedicated}/interfaces', [AdminController::class, 'interfaces'])->name('dedicated.interfaces');
    Route::delete('dedicated-agents/{dedicated}', [AdminController::class, 'destroy'])->name('dedicated.destroy');

    // Inbound resellers moved here from the core with the module.
    Route::get('inbound-allocations', [InboundAllocationController::class, 'index'])->name('inbound-allocations.index');
    Route::get('inbound-allocations/create', [InboundAllocationController::class, 'create'])->name('inbound-allocations.create');
    Route::post('inbound-allocations', [InboundAllocationController::class, 'store'])->name('inbound-allocations.store');
    Route::get('inbound-allocations/{inboundAllocation}/edit', [InboundAllocationController::class, 'edit'])->name('inbound-allocations.edit');
    Route::put('inbound-allocations/{inboundAllocation}', [InboundAllocationController::class, 'update'])->name('inbound-allocations.update');
    Route::post('inbound-allocations/{inboundAllocation}/suspend', [InboundAllocationController::class, 'suspend'])->name('inbound-allocations.suspend');
    Route::post('inbound-allocations/{inboundAllocation}/resume', [InboundAllocationController::class, 'resume'])->name('inbound-allocations.resume');
    Route::post('inbound-allocations/{inboundAllocation}/bill', [InboundAllocationController::class, 'bill'])->name('inbound-allocations.bill');
});

Route::prefix(PortalPaths::slug('agent'))->name('agent.')->middleware(['auth', 'role:agent', 'log.activity'])->group(function (): void {
    Route::get('dedicated', [AgentController::class, 'index'])->name('dedicated.index');
    Route::get('dedicated/packages/create', [AgentController::class, 'createPackage'])->name('dedicated.packages.create');
    Route::post('dedicated/packages', [AgentController::class, 'storePackage'])->name('dedicated.packages.store');
    Route::get('dedicated/packages/{package}/edit', [AgentController::class, 'editPackage'])->name('dedicated.packages.edit');
    Route::put('dedicated/packages/{package}', [AgentController::class, 'updatePackage'])->name('dedicated.packages.update');
    Route::delete('dedicated/packages/{package}', [AgentController::class, 'destroyPackage'])->name('dedicated.packages.destroy');

    Route::get('inbounds', [InboundController::class, 'index'])->name('inbounds.index');
    Route::get('inbounds/{allocation}/packages/create', [InboundController::class, 'createPackage'])->name('inbounds.packages.create');
    Route::post('inbounds/{allocation}/packages', [InboundController::class, 'storePackage'])->name('inbounds.packages.store');
    Route::get('inbounds/packages/{package}/edit', [InboundController::class, 'editPackage'])->name('inbounds.packages.edit');
    Route::put('inbounds/packages/{package}', [InboundController::class, 'updatePackage'])->name('inbounds.packages.update');
    Route::delete('inbounds/packages/{package}', [InboundController::class, 'destroyPackage'])->name('inbounds.packages.destroy');
});
