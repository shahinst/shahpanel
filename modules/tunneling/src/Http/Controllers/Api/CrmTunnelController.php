<?php

namespace Modules\Tunneling\Http\Controllers\Api;

use AppHttpControllersController;
use App\CrmTunneling\SubnetAllocator;
use App\CrmTunneling\TunnelManager;
use App\Enums\CrmTunnelStatus;
use App\Enums\CrmTunnelType;
use App\Http\Controllers\Controller;
use App\Jobs\CrmTunneling\ProvisionTunnelJob;
use App\Models\CrmTunnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CrmTunnelController extends Controller
{
    public function index(): JsonResponse
    {
        $tunnels = CrmTunnel::query()
            ->with(['hubServer:id,name', 'exitServer:id,name'])
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $tunnels]);
    }

    public function store(Request $request, SubnetAllocator $allocator): JsonResponse
    {
        $data = $request->validate([
            'hub_server_id' => ['required', 'integer', 'exists:servers,id'],
            'exit_server_id' => ['required', 'integer', 'exists:servers,id', 'different:hub_server_id'],
            'type' => ['sometimes', Rule::enum(CrmTunnelType::class)],
            'name' => ['sometimes', 'string', 'max:64'],
            'keepalive' => ['sometimes', 'string', 'max:16'],
        ]);

        $subnet = $allocator->allocate();

        $tunnel = CrmTunnel::create([
            'type' => $data['type'] ?? CrmTunnelType::Gre,
            'hub_server_id' => $data['hub_server_id'],
            'exit_server_id' => $data['exit_server_id'],
            'name' => $data['name'] ?? 'GRE-'.now()->format('His'),
            'tunnel_subnet' => $subnet['subnet'],
            'hub_tunnel_ip' => $subnet['hub_ip'],
            'exit_tunnel_ip' => $subnet['exit_ip'],
            'keepalive' => $data['keepalive'] ?? '10s,3',
            'comment_tag' => 'CRM-TUN-temp',
            'status' => CrmTunnelStatus::Pending,
        ]);

        $tunnel->update(['comment_tag' => 'CRM-TUN-'.$tunnel->id]);

        ProvisionTunnelJob::dispatch($tunnel->id);

        return response()->json(['data' => $tunnel->fresh()->load('hubServer', 'exitServer')], 201);
    }

    public function show(CrmTunnel $tunnel): JsonResponse
    {
        return response()->json([
            'data' => $tunnel->load(['hubServer', 'exitServer', 'logs']),
        ]);
    }

    public function redeploy(CrmTunnel $tunnel): JsonResponse
    {
        ProvisionTunnelJob::dispatch($tunnel->id);

        return response()->json(['message' => 'Provision job queued', 'tunnel_id' => $tunnel->id]);
    }

    public function test(CrmTunnel $tunnel, TunnelManager $manager): JsonResponse
    {
        $result = $manager->testOnly($tunnel);

        return response()->json([
            'data' => $tunnel->fresh(),
            'test' => $result->toArray(),
        ]);
    }

    public function destroy(CrmTunnel $tunnel, TunnelManager $manager): JsonResponse
    {
        $manager->teardown($tunnel);
        $tunnel->delete();

        return response()->json(['message' => 'Tunnel removed']);
    }

    public function logs(CrmTunnel $tunnel): JsonResponse
    {
        return response()->json([
            'data' => $tunnel->logs()->limit(100)->get(),
        ]);
    }
}
