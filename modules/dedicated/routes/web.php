<?php

use App\Http\Controllers\Admin\InboundAllocationController;
use App\Http\Controllers\Agent\InboundController;
use App\Http\Middleware\RestrictAdminByIp;
use App\Support\PortalPaths;
use Illuminate\Support\Facades\Route;
use Modules\Dedicated\Http\Controllers\AdminController;
use Modules\Dedicated\Http\Controllers\AgentController;
use Modules\Dedicated\Http\Controllers\CommissionController;
use Modules\Dedicated\Http\Controllers\InboundAgentController;
use Modules\Dedicated\Http\Controllers\InboundVolumeController;

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
    Route::get('dedicated-agents/create', [AdminController::class, 'create'])->name('dedicated.create');
    Route::get('dedicated-agents/{agent}/settings', [AdminController::class, 'edit'])->name('dedicated.edit');
    Route::post('dedicated-agents/{agent}/servers', [AdminController::class, 'addServer'])->name('dedicated.servers.store');
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

    // Inbound agents: created with their inbound, fed by volume packs.
    Route::get('inbound-agents', [InboundAgentController::class, 'index'])->name('inbound-agents.index');
    Route::post('inbound-agents', [InboundAgentController::class, 'store'])->name('inbound-agents.store');
    Route::get('inbound-agents/create', [InboundAgentController::class, 'create'])->name('inbound-agents.create');
    Route::get('inbound-agents/volume', [InboundAgentController::class, 'volume'])->name('inbound-agents.volume');
    Route::get('inbound-agents/{agent}/settings', [InboundAgentController::class, 'edit'])->name('inbound-agents.edit');
    Route::post('inbound-agents/{agent}/inbounds', [InboundAgentController::class, 'addInbound'])->name('inbound-agents.inbounds.store');
    Route::put('inbound-agents/inbounds/{allocation}', [InboundAgentController::class, 'updateInbound'])->name('inbound-agents.inbounds.update');
    Route::post('inbound-agents/packs', [InboundAgentController::class, 'storePack'])->name('inbound-agents.packs.store');
    Route::put('inbound-agents/packs/{pack}', [InboundAgentController::class, 'updatePack'])->name('inbound-agents.packs.update');
    Route::delete('inbound-agents/packs/{pack}', [InboundAgentController::class, 'destroyPack'])->name('inbound-agents.packs.destroy');
    Route::post('inbound-agents/requests/{chargeRequest}/approve', [InboundAgentController::class, 'approve'])->name('inbound-agents.requests.approve')->middleware('throttle:money-actions');
    Route::post('inbound-agents/requests/{chargeRequest}/reject', [InboundAgentController::class, 'reject'])->name('inbound-agents.requests.reject');
});

Route::prefix(PortalPaths::slug('agent'))->name('agent.')->middleware(['auth', 'role:agent', 'log.activity'])->group(function (): void {
    Route::get('dedicated', [AgentController::class, 'index'])->name('dedicated.index');
    Route::get('dedicated/packages/create', [AgentController::class, 'createPackage'])->name('dedicated.packages.create');
    Route::post('dedicated/packages', [AgentController::class, 'storePackage'])->name('dedicated.packages.store');
    Route::get('dedicated/packages/{package}/edit', [AgentController::class, 'editPackage'])->name('dedicated.packages.edit');
    Route::put('dedicated/packages/{package}', [AgentController::class, 'updatePackage'])->name('dedicated.packages.update');
    Route::delete('dedicated/packages/{package}', [AgentController::class, 'destroyPackage'])->name('dedicated.packages.destroy');

    // Commission terms for the agent's sellers on the agent's own packages.
    Route::get('commissions', [CommissionController::class, 'index'])->name('dedicated.commissions');
    Route::post('commissions/{seller}', [CommissionController::class, 'update'])->name('dedicated.commissions.update');

    Route::get('inbounds', [InboundController::class, 'index'])->name('inbounds.index');
    Route::get('inbounds/{allocation}/packages/create', [InboundController::class, 'createPackage'])->name('inbounds.packages.create');
    Route::post('inbounds/{allocation}/packages', [InboundController::class, 'storePackage'])->name('inbounds.packages.store');
    Route::get('inbounds/packages/{package}/edit', [InboundController::class, 'editPackage'])->name('inbounds.packages.edit');
    Route::put('inbounds/packages/{package}', [InboundController::class, 'updatePackage'])->name('inbounds.packages.update');
    Route::delete('inbounds/packages/{package}', [InboundController::class, 'destroyPackage'])->name('inbounds.packages.destroy');
    Route::get('inbound-volume', [InboundVolumeController::class, 'index'])->name('inbound-volume.index');
    Route::post('inbound-volume/{allocation}', [InboundVolumeController::class, 'store'])->name('inbound-volume.store');
});
