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

        // These servers have no inbounds to import from -- the wizard's fallback
        // branch would render an inbound picker that can never be populated.
        if ($server->isAnyconnectFamily()) {
            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', __('servers.operation_not_supported_anyconnect'));
        }

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

        // A 300+ client inbound posts three or four fields per row, which runs
        // past PHP's max_input_vars (1000 by default). PHP then silently drops
        // the tail of the form, so the last rows arrived without an owner even
        // though one was picked. The form now sends every row as one JSON field;
        // the per-row array is kept as a fallback for browsers without script.
        $rows = $this->decodeAssignmentsJson($request->input('assignments_json'));

        if ($rows !== null) {
            $request->merge(['assignments' => $rows]);
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
        $assignedUuids = [];

        foreach ($validated['assignments'] as $row) {
            // A row left without an owner is not an error: it stays in the list so
            // it can be assigned and imported in a later pass.
            if (! empty($row['skip']) || empty($row['owner_id'])) {
                continue;
            }

            $assignedUuids[(string) $row['uuid']] = true;

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

        $message = __('servers.import_done', [
            'created' => $result['created'] ?? $result['imported'] ?? 0,
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
        ]);

        $log = array_merge($result['lines'] ?? [], $result['errors'] ?? []);
        $flashType = ($result['errors'] ?? []) === [] ? 'success' : 'warning';

        // Clients that had no owner this time are kept for the next pass, so a
        // 500-client inbound can be imported in as many rounds as the admin likes.
        // Rows that already exist in the panel are not carried over: they were
        // shown only to offer an owner change.
        $remaining = array_values(array_filter(
            $sessionData['clients'],
            fn (array $client): bool => ! isset($assignedUuids[(string) ($client['uuid'] ?? '')])
                && empty($client['existing_account_id'])
        ));

        if ($remaining !== []) {
            session([
                $this->sessionKey($server) => array_merge($sessionData, [
                    'clients' => $remaining,
                    'total' => count($remaining),
                ]),
            ]);

            return redirect()
                ->route('admin.servers.import-clients.assign', $server)
                ->with($flashType, $message.' '.__('servers.import_remaining_left', ['count' => count($remaining)]))
                ->with('operation_log', $log);
        }

        session()->forget($this->sessionKey($server));

        return redirect()
            ->route('admin.servers.show', $server)
            ->with($flashType, $message)
            ->with('operation_log', $log);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function decodeAssignmentsJson(mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    protected function sessionKey(Server $server): string
    {
        return 'server_client_import_'.$server->id;
    }
}
