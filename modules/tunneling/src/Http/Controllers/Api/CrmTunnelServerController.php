<?php

namespace Modules\Tunneling\Http\Controllers\Api;

use AppHttpControllersController;
use App\CrmTunneling\MikrotikClient;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmTunnelServerController extends Controller
{
    public function index(): JsonResponse
    {
        $servers = Server::query()
            ->where('type', 'mikrotik')
            ->orderBy('name')
            ->get(['id', 'name', 'location', 'host', 'public_ip', 'port', 'is_hub', 'wan_interface', 'api_ssl', 'is_active', 'last_health_status']);

        return response()->json(['data' => $servers]);
    }

    public function update(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate([
            'is_hub' => ['sometimes', 'boolean'],
            'wan_interface' => ['sometimes', 'string', 'max:64'],
            'api_ssl' => ['sometimes', 'boolean'],
            'public_ip' => ['sometimes', 'nullable', 'ip'],
        ]);

        $server->update($data);

        return response()->json(['data' => $server->fresh()]);
    }

    public function testConnection(Server $server, MikrotikClient $client): JsonResponse
    {
        $ok = $client->testConnection($server);

        $server->forceFill([
            'last_health_check_at' => now(),
            'last_health_status' => $ok ? 'online' : 'offline',
        ])->save();

        return response()->json([
            'ok' => $ok,
            'server_id' => $server->id,
            'message' => $ok ? 'Connection OK' : 'Connection failed',
        ], $ok ? 200 : 422);
    }
}
