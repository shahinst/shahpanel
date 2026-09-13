<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerInterface;
use App\Models\User;
use App\Enums\UserRole;
use App\Services\MikrotikClientImportService;
use App\Services\Pasarguard\PasarguardClientImportService;
use App\Services\SanaeiClientImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServerClientImportController extends Controller
{
    public function create(Server $server): View|RedirectResponse
    {
        $this->authorize('update', $server);

        if ($server->isPasarguard()) {
            return view('admin.servers.import-clients', compact('server'));
        }

        if ($server->isMikrotik()) {
            $profiles = ServerInterface::query()
                ->where('server_id', $server->id)
                ->where('category', 'ppp')
                ->orderBy('name')
                ->get();

            return view('admin.servers.import-clients', compact('server', 'profiles'));
        }

        $inbounds = ServerInterface::query()
            ->where('server_id', $server->id)
            ->where('category', 'inbound')
            ->orderBy('name')
            ->get();

        return view('admin.servers.import-clients', compact('server', 'inbounds'));
    }

    public function preview(
        Request $request,
        Server $server,
        SanaeiClientImportService $sanaeiImportService,
        MikrotikClientImportService $mikrotikImportService,
        PasarguardClientImportService $pasarguardImportService,
    ): RedirectResponse {
        $this->authorize('update', $server);

        if ($server->isPasarguard()) {
            try {
                $payload = $pasarguardImportService->fetchForAssignment($server);
            } catch (\Throwable $exception) {
                return redirect()
                    ->route('admin.servers.import-clients.create', $server)
                    ->with('error', $exception->getMessage());
            }

            $source = 'pasarguard';
        } elseif ($server->isMikrotik() && $request->input('import_type') === 'wireguard') {
            try {
                $payload = $mikrotikImportService->fetchWireguardForAssignment($server);
            } catch (\Throwable $exception) {
                return redirect()
                    ->route('admin.servers.import-clients.create', $server)
                    ->with('error', $exception->getMessage());
            }

            $source = 'mikrotik';
        } elseif ($server->isMikrotik()) {
            $validated = $request->validate([
                'profile_key' => ['required', 'string', 'max:128'],
            ]);

            try {
                $payload = $mikrotikImportService->fetchForAssignment($server, $validated['profile_key']);
            } catch (\Throwable $exception) {
                return redirect()
                    ->route('admin.servers.import-clients.create', $server)
                    ->with('error', $exception->getMessage());
            }

            $source = 'mikrotik';
        } else {
            $validated = $request->validate([
                'inbound_id' => ['required', 'integer', 'min:1'],
            ]);

            $inboundId = (int) $validated['inbound_id'];

            try {
                $payload = $sanaeiImportService->fetchForAssignment($server, $inboundId);
            } catch (\Throwable $exception) {
                return redirect()
                    ->route('admin.servers.import-clients.create', $server)
                    ->with('error', $exception->getMessage());
            }

            $source = 'sanaei';
        }

        if ($payload['clients'] === []) {
            return redirect()
                ->route('admin.servers.import-clients.create', $server)
                ->with('warning', __('servers.import_no_clients'));
        }

        session([
            $this->sessionKey($server) => array_merge($payload, [
                'source' => $source,
            ]),
        ]);

        return redirect()->route('admin.servers.import-clients.assign', $server);
    }

    public function assign(Server $server): View|RedirectResponse
    {
        $this->authorize('update', $server);

        $sessionData = session($this->sessionKey($server));

        if (! is_array($sessionData) || empty($sessionData['clients'])) {
            return redirect()
                ->route('admin.servers.import-clients.create', $server)
                ->with('error', __('servers.import_session_expired'));
        }

        $owners = User::query()
            ->whereIn('role', [UserRole::Agent, UserRole::Seller])
            ->orderBy('role')
            ->orderBy('full_name')
            ->get()
            ->groupBy(fn (User $user) => $user->role->value);

        $defaultSource = $server->isMikrotik()
            ? 'mikrotik'
            : ($server->isPasarguard() ? 'pasarguard' : 'sanaei');

        $payload = [
            'source' => $sessionData['source'] ?? $defaultSource,
            'inbound_id' => $sessionData['inbound_id'] ?? null,
            'inbound_name' => $sessionData['inbound_name'] ?? $sessionData['profile_name'] ?? '',
            'profile_key' => $sessionData['profile_key'] ?? null,
            'profile_name' => $sessionData['profile_name'] ?? null,
            'protocol' => $sessionData['protocol'] ?? null,
            'service_type' => $sessionData['service_type'] ?? null,
            'clients' => $sessionData['clients'],
            'packages' => $sessionData['packages'] ?? [],
            'total' => $sessionData['total'] ?? count($sessionData['clients']),
        ];

        return view('admin.servers.import-clients-assign', [
            'server' => $server,
            'payload' => $payload,
            'packages' => $payload['packages'],
            'owners' => $owners,
        ]);
    }

    public function store(
        Request $request,
        Server $server,
        SanaeiClientImportService $sanaeiImportService,
        MikrotikClientImportService $mikrotikImportService,
        PasarguardClientImportService $pasarguardImportService,
    ): RedirectResponse {
        $this->authorize('update', $server);

        $sessionData = session($this->sessionKey($server));

        if (! is_array($sessionData) || empty($sessionData['clients'])) {
            return redirect()
                ->route('admin.servers.import-clients.create', $server)
                ->with('error', __('servers.import_session_expired'));
        }

        $validated = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.uuid' => ['required', 'string'],
            'assignments.*.owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'assignments.*.package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'assignments.*.update_existing' => ['sometimes', 'boolean'],
            'assignments.*.skip' => ['sometimes', 'boolean'],
        ]);

        $assignments = [];

        foreach ($validated['assignments'] as $row) {
            if (! empty($row['skip'])) {
                continue;
            }

            if (empty($row['owner_id'])) {
                return redirect()
                    ->route('admin.servers.import-clients.assign', $server)
                    ->withInput()
                    ->with('error', __('servers.import_owner_required'));
            }

            $assignments[] = [
                'uuid' => $row['uuid'],
                'owner_id' => (int) $row['owner_id'],
                'package_id' => ! empty($row['package_id']) ? (int) $row['package_id'] : null,
                'update_existing' => ! empty($row['update_existing']),
            ];
        }

        if ($assignments === []) {
            return redirect()
                ->route('admin.servers.import-clients.assign', $server)
                ->with('error', __('servers.import_nothing_selected'));
        }

        try {
            $source = (string) ($sessionData['source'] ?? '');

            if ($source === 'pasarguard' || $server->isPasarguard()) {
                $result = $pasarguardImportService->import($server, $assignments);
            } elseif ($source === 'mikrotik' || $server->isMikrotik()) {
                $serviceType = $sessionData['service_type'] instanceof \App\Enums\ServiceType
                    ? $sessionData['service_type']
                    : \App\Enums\ServiceType::from((string) $sessionData['service_type']);

                $result = $mikrotikImportService->import(
                    $server,
                    isset($sessionData['profile_key']) ? (string) $sessionData['profile_key'] : null,
                    $serviceType,
                    $assignments
                );
            } else {
                $serviceType = $sessionData['service_type'] instanceof \App\Enums\ServiceType
                    ? $sessionData['service_type']
                    : \App\Enums\ServiceType::from((string) $sessionData['service_type']);

                $result = $sanaeiImportService->import(
                    $server,
                    (int) $sessionData['inbound_id'],
                    $serviceType,
                    $assignments
                );
            }
        } catch (\Throwable $exception) {
            return redirect()
                ->route('admin.servers.import-clients.assign', $server)
                ->with('error', $exception->getMessage());
        }

        session()->forget($this->sessionKey($server));

        $message = __('servers.import_done', [
            'created' => $result['created'] ?? $result['imported'] ?? 0,
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
        ]);

        $log = array_merge($result['lines'] ?? [], $result['errors'] ?? []);

        return redirect()
            ->route('admin.servers.show', $server)
            ->with($log === [] || ($result['errors'] ?? []) === [] ? 'success' : 'warning', $message)
            ->with('operation_log', $log);
    }

    protected function sessionKey(Server $server): string
    {
        return 'server_client_import_'.$server->id;
    }
}
