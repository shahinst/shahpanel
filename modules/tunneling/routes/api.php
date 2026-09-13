<?php

use Illuminate\Support\Facades\Route;
use Modules\Tunneling\Http\Controllers\Api\CrmTunnelController;
use Modules\Tunneling\Http\Controllers\Api\CrmTunnelServerController;

Route::middleware(['web', 'auth', 'role:admin'])->group(function (): void {
    Route::get('servers', [CrmTunnelServerController::class, 'index']);
    Route::patch('servers/{server}', [CrmTunnelServerController::class, 'update']);
    Route::post('servers/{server}/test-connection', [CrmTunnelServerController::class, 'testConnection']);

    Route::get('tunnels', [CrmTunnelController::class, 'index']);
    Route::post('tunnels', [CrmTunnelController::class, 'store']);
    Route::get('tunnels/{tunnel}', [CrmTunnelController::class, 'show']);
    Route::post('tunnels/{tunnel}/redeploy', [CrmTunnelController::class, 'redeploy']);
    Route::post('tunnels/{tunnel}/test', [CrmTunnelController::class, 'test']);
    Route::delete('tunnels/{tunnel}', [CrmTunnelController::class, 'destroy']);
    Route::get('tunnels/{tunnel}/logs', [CrmTunnelController::class, 'logs']);
});

