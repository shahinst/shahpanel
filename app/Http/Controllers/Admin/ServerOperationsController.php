<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerInterface;
use App\Services\MikrotikPppProfileService;
use App\Services\MikrotikServerProfilePushService;
use App\Services\MikrotikWireguardInterfaceService;
use App\Services\SanaeiServerMigrationService;
use App\Services\ServerAccountPushService;
use App\Services\ServerConnectionService;
use App\Services\ServerInterfaceSyncService;
use App\Services\ServerL2tpIpsecService;
use App\Services\ServerOvpnProfileService;
use App\Services\SyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class ServerOperationsController extends Controller
{
    public function testConnection(
        Server $server,
        ServerConnectionService $connectionService
    ): RedirectResponse {
        $this->authorize('update', $server);

        $result = $connectionService->test($server);
        $lines = $this->formatConnectionLog($result, $server);

        return redirect()
            ->route('admin.servers.show', $server)
            ->with($result['ok'] ? 'success' : 'error', $result['message'])
            ->with('operation_log', $lines);
    }

    public function pullFromRouter(
        Server $server,
        ServerInterfaceSyncService $interfaceSyncService
    ): RedirectResponse {
        $this->authorize('update', $server);

        return $this->runInterfacePull($server, $interfaceSyncService);
    }

    public function pushProfiles(
        Request $request,
        Server $server,
        MikrotikServerProfilePushService $profilePushService,
        ServerInterfaceSyncService $interfaceSyncService,
    ): RedirectResponse {
        $this->authorize('update', $server);

        $request->validate([
            'confirmed' => ['accepted'],
        ]);

        if (! $server->isMikrotik()) {
            return $this->runInterfacePull($server, $interfaceSyncService);
        }

        @set_time_limit(max(120, (int) config('shahpanel.mikrotik.inline_max_seconds', 600)));

        try {
            $pushResult = $profilePushService->push($server);
            $pullResult = $interfaceSyncService->sync($server);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', __('servers.sync_profiles_failed', ['message' => $exception->getMessage()]))
                ->with('operation_log', [__('backend.server_log_error', ['message' => $exception->getMessage()])]);
        }

        $lines = array_merge($pushResult['lines'], $pullResult['lines'], $pushResult['errors'], $pullResult['errors']);
        $hasErrors = $pushResult['errors'] !== [] || $pullResult['errors'] !== [];

        return redirect()
            ->route('admin.servers.show', $server)
            ->with($hasErrors ? 'warning' : 'success', __('servers.profiles_pushed', [
                'restored' => $pushResult['restored'],
                'synced' => $pullResult['synced'],
            ]))
            ->with('operation_log', $lines);
    }

    public function syncInterfaces(
        Server $server,
        ServerInterfaceSyncService $interfaceSyncService
    ): RedirectResponse {
        $this->authorize('update', $server);

        return $this->runInterfacePull($server, $interfaceSyncService);
    }

    protected function runInterfacePull(
        Server $server,
        ServerInterfaceSyncService $interfaceSyncService,
    ): RedirectResponse {
        // ServerInterfaceSyncService::sync() ends in a Sanaei default arm, so an
        // ocserv or Cisco server would be sent a 3x-ui login it cannot answer.
        // The buttons are hidden in the view; this stops a direct POST.
        if ($server->isAnyconnectFamily()) {
            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', __('servers.operation_not_supported_anyconnect'));
        }

        @set_time_limit(max(120, (int) config('shahpanel.mikrotik.inline_max_seconds', 600)));

        try {
            $result = $interfaceSyncService->sync($server);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', __('servers.sync_profiles_failed', ['message' => $exception->getMessage()]))
                ->with('operation_log', [__('backend.server_log_error', ['message' => $exception->getMessage()])]);
        }

        $lines = array_merge($result['lines'], $result['errors']);

        $translationKey = match (true) {
            $server->isRemnawave() => $result['synced'] > 0 ? 'servers.remnawave_squads_synced_count' : 'servers.remnawave_sync_catalog_failed',
            $server->isPanelBacked() => 'servers.inbounds_synced',
            default => 'servers.router_refresh_done',
        };

        $messageParams = $server->isRemnawave()
            ? ['count' => $result['synced']]
            : ['count' => $result['synced']];

        return redirect()
            ->route('admin.servers.show', $server)
            ->with($result['errors'] === [] ? 'success' : 'warning', __($translationKey, $messageParams))
            ->with('operation_log', $lines);
    }

    public function syncAccounts(
        Request $request,
        Server $server,
        ServerAccountPushService $pushService,
    ): RedirectResponse {
        $this->authorize('update', $server);

        $request->validate([
            'confirmed' => ['accepted'],
        ]);

        $request->merge(['only_missing' => false]);

        return $this->pushAccounts($request, $server, $pushService);
    }

    public function migrateSanaeiFrom(
        Request $request,
        Server $server,
        SanaeiServerMigrationService $migration,
    ): RedirectResponse {
        $this->authorize('update', $server);

        if (! $server->isSanaei()) {
            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', __('servers.migrate_sanaei_only'));
        }

        $validated = $request->validate([
            'from_server_id' => ['required', 'integer', 'exists:servers,id'],
        ]);

        $from = Server::query()->findOrFail((int) $validated['from_server_id']);

        @set_time_limit(max(120, (int) config('shahpanel.mikrotik.inline_max_seconds', 600)));

        try {
            $result = $migration->migrateFromServer(
                $from,
                $server,
                $request->user(),
                dryRun: false,
                syncInbounds: true,
            );
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', $e->getMessage());
        }

        $lines = array_merge($result['lines'], $result['errors']);

        return redirect()
            ->route('admin.servers.show', $server)
            ->with($result['failed'] === 0 && $result['errors'] === [] ? 'success' : 'warning', __('servers.migrate_sanaei_done', [
                'transferred' => $result['transferred'],
                'failed' => $result['failed'] + count($result['errors']),
            ]))
            ->with('operation_log', $lines);
    }

    public function pushAccounts(
        Request $request,
        Server $server,
        ServerAccountPushService $pushService
    ): RedirectResponse {
        $this->authorize('update', $server);

        @set_time_limit(max(120, (int) config('shahpanel.mikrotik.inline_max_seconds', 600)));

        $onlyMissing = $request->boolean('only_missing', false);

        try {
            $result = $pushService->pushAll($server, $onlyMissing);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', $exception->getMessage());
        }

        $lines = array_merge($result['lines'], $result['errors']);

        return redirect()
            ->route('admin.servers.show', $server)
            ->with($result['failed'] === 0 ? 'success' : 'warning', __('servers.accounts_pushed', [
                'pushed' => $result['pushed'],
                'failed' => $result['failed'],
            ]))
            ->with('operation_log', $lines);
    }

    public function syncTraffic(
        Server $server,
        SyncService $syncService
    ): RedirectResponse {
        $this->authorize('update', $server);

        @set_time_limit(max(120, (int) config('shahpanel.mikrotik.inline_max_seconds', 600)));

        try {
            $log = $syncService->syncServer($server);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', $exception->getMessage());
        }
        $lines = [
            __('backend.server_log_status', ['status' => $log->status->value]),
            __('backend.server_log_accounts_synced', ['count' => $log->accounts_synced]),
            __('backend.server_log_errors_count', ['count' => $log->errors_count]),
        ];

        if ($log->error_details) {
            $lines[] = $log->error_details;
        }

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', __('servers.traffic_synced'))
            ->with('operation_log', $lines);
    }

    /**
     * @param  array{ok: bool, message: string, details: array<string, mixed>}  $result
     * @return list<string>
     */
    protected function formatConnectionLog(array $result, Server $server): array
    {
        $lines = [$result['message']];
        $details = $result['details'] ?? [];

        foreach ([
            'panel_url' => __('backend.server_detail_panel_url'),
            'api_url' => __('backend.server_detail_api_url'),
            'api_prefix' => __('backend.server_detail_api_prefix'),
            'admin_username' => __('backend.server_detail_admin_username'),
            'panel_version' => __('backend.server_detail_panel_version'),
            'inbound_count' => __('backend.server_detail_inbound_count'),
            'group_count' => __('backend.server_detail_group_count'),
            'squad_count' => __('backend.server_detail_squad_count'),
            'node_count' => __('backend.server_detail_node_count'),
            'groups_synced_at' => __('backend.server_detail_groups_synced_at'),
            'user_count' => __('backend.server_detail_user_count'),
            'error' => __('backend.server_detail_error'),
        ] as $key => $label) {
            if (! empty($details[$key])) {
                $lines[] = "{$label}: {$details[$key]}";
            }
        }

        if (! empty($details['permissions']) && is_array($details['permissions'])) {
            $allowed = array_keys(array_filter($details['permissions']));
            $denied = array_keys(array_filter($details['permissions'], fn ($v) => ! $v));
            if ($allowed !== []) {
                $lines[] = __('backend.server_log_permissions_allowed', ['list' => implode(', ', $allowed)]);
            }
            if ($denied !== []) {
                $lines[] = __('backend.server_log_permissions_denied', ['list' => implode(', ', $denied)]);
            }
        }

        if (! empty($details['tried_urls']) && is_array($details['tried_urls'])) {
            $lines[] = __('backend.server_log_tried_urls', ['list' => implode(' | ', $details['tried_urls'])]);
        }

        if (! empty($details['warnings']) && is_array($details['warnings'])) {
            $lines[] = __('backend.server_log_warnings', ['list' => implode(' — ', $details['warnings'])]);
        }

        if (! empty($details['groups']) && is_array($details['groups'])) {
            foreach ($details['groups'] as $group) {
                if (! is_array($group)) {
                    continue;
                }
                $lines[] = __('backend.server_log_group_entry', [
                    'id' => $group['id'] ?? '?',
                    'name' => $group['name'] ?? '',
                ]);
            }
        }

        if (! empty($details['squads']) && is_array($details['squads'])) {
            foreach ($details['squads'] as $squad) {
                if (! is_array($squad)) {
                    continue;
                }
                $lines[] = 'Squad: '.($squad['name'] ?? '').' ('.($squad['uuid'] ?? '').')';
            }
        }

        if ($server->isPasarguard() && $server->pasarguard_groups_synced_at) {
            $lines[] = __('backend.server_log_stored_groups', [
                'count' => persian_digits(count((array) $server->pasarguard_groups)),
                'at' => $server->pasarguard_groups_synced_at->format('Y-m-d H:i'),
            ]);
        }

        if (! empty($details['debug']) && is_array($details['debug'])) {
            $lines[] = '--- '.(__('servers.connection_debug')).' ---';
            foreach ($details['debug'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $step = $entry['step'] ?? 'step';
                unset($entry['step'], $entry['at']);
                $parts = [];
                foreach ($entry as $k => $v) {
                    if ($v === null || $v === '') {
                        continue;
                    }
                    $parts[] = $k.'='.(is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE));
                }
                $lines[] = "[{$step}] ".implode(' | ', $parts);
            }
            $logFile = match ($server->type) {
                \App\Enums\ServerType::Pasarguard => 'storage/logs/pasarguard.log',
                \App\Enums\ServerType::Sanaei => 'storage/logs/sanaei.log',
                \App\Enums\ServerType::Remnawave => 'storage/logs/remnawave.log',
                default => 'storage/logs/laravel.log',
            };
            $lines[] = __('backend.server_log_full_log', ['file' => $logFile]);
        }

        return $lines;
    }

    public function storeWireguardInterface(
        Request $request,
        Server $server,
        MikrotikWireguardInterfaceService $wireguardInterfaces,
    ): RedirectResponse {
        $this->authorize('update', $server);

        if (! $server->isMikrotik()) {
            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', __('servers.wireguard_mikrotik_only'));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z][a-zA-Z0-9_-]*$/'],
            'subnet' => ['required', 'string', 'max:32'],
            'listen_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'speed_limit_mbps' => ['nullable', 'integer', 'in:5,10,20,30,40,50'],
        ]);

        try {
            $interface = $wireguardInterfaces->create(
                $server,
                $validated['name'],
                $validated['subnet'],
                isset($validated['listen_port']) ? (int) $validated['listen_port'] : null,
                isset($validated['speed_limit_mbps']) ? (int) $validated['speed_limit_mbps'] : null,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.servers.show', $server)
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        $subnet = $interface->meta['subnet'] ?? $validated['subnet'];

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', __('servers.wireguard_interface_created', [
                'name' => $interface->name,
                'subnet' => $subnet,
            ]))
            ->with('operation_log', [
                __('servers.wireguard_interface_created_log', [
                    'name' => $interface->name,
                    'subnet' => $subnet,
                    'port' => persian_digits((string) ($interface->port ?? '—')),
                ]),
            ]);
    }

    public function updateWireguardInterface(
        Request $request,
        Server $server,
        ServerInterface $serverInterface,
        MikrotikWireguardInterfaceService $wireguardInterfaces,
    ): RedirectResponse {
        $this->authorize('update', $server);

        if (! $server->isMikrotik()) {
            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', __('servers.wireguard_mikrotik_only'));
        }

        if ($serverInterface->server_id !== $server->id || $serverInterface->category !== 'wireguard') {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z][a-zA-Z0-9_-]*$/'],
            'speed_limit_mbps' => ['nullable', 'integer', 'in:5,10,20,30,40,50'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        @set_time_limit(max(120, (int) config('shahpanel.mikrotik.inline_max_seconds', 600)));

        try {
            $result = $wireguardInterfaces->updateManaged(
                $server,
                $serverInterface,
                $validated['name'],
                isset($validated['speed_limit_mbps']) ? (int) $validated['speed_limit_mbps'] : null,
                $request->boolean('is_enabled'),
            );
            $interface = $result['interface'];
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', $exception->getMessage());
        }

        $log = [__('servers.wireguard_interface_updated', ['name' => $interface->name])];

        if ($result['speed_limit_mbps'] !== null && (int) $result['speed_limit_mbps'] > 0) {
            $log[] = __('servers.speed_queues_applied', [
                'name' => $interface->name,
                'count' => persian_digits((int) $result['queues_applied']),
                'speed' => persian_digits((int) $result['speed_limit_mbps']),
            ]);
        } elseif ($result['speed_limit_mbps'] === null) {
            $log[] = __('servers.speed_queues_removed', ['name' => $interface->name]);
        }

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', __('servers.wireguard_interface_updated', ['name' => $interface->name]))
            ->with('operation_log', $log);
    }

    public function storePppProfile(
        Request $request,
        Server $server,
        MikrotikPppProfileService $pppProfiles,
    ): RedirectResponse {
        $this->authorize('update', $server);

        if (! $server->isMikrotik()) {
            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', __('servers.ppp_mikrotik_only'));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z][a-zA-Z0-9_-]*$/'],
            'subnet' => ['required', 'string', 'max:32'],
            'pool_name' => ['nullable', 'string', 'max:32', 'regex:/^[a-zA-Z][a-zA-Z0-9_-]*$/'],
            'protocol' => ['required', 'string', 'in:any,l2tp,ovpn,pptp,sstp'],
            'use_encryption' => ['nullable', 'boolean'],
            'speed_limit_mbps' => ['nullable', 'integer', 'in:5,10,20,30,40,50'],
        ]);

        try {
            $profile = $pppProfiles->create(
                $server,
                $validated['name'],
                $validated['subnet'],
                $validated['protocol'],
                $request->boolean('use_encryption'),
                $validated['pool_name'] ?? null,
                isset($validated['speed_limit_mbps']) ? (int) $validated['speed_limit_mbps'] : null,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.servers.show', $server)
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        $meta = $profile->meta ?? [];

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', __('servers.ppp_profile_created', [
                'name' => $profile->name,
                'subnet' => $meta['subnet'] ?? $validated['subnet'],
            ]))
            ->with('operation_log', [
                __('servers.ppp_profile_created_log', [
                    'name' => $profile->name,
                    'pool' => $meta['pool_name'] ?? '—',
                    'gateway' => $meta['local_address'] ?? '—',
                    'protocol' => $profile->protocol ?? $validated['protocol'],
                ]),
            ]);
    }

    public function storeOvpnProfile(
        Request $request,
        Server $server,
        ServerOvpnProfileService $ovpnProfiles,
    ): RedirectResponse {
        $this->authorize('update', $server);

        if (! $server->isMikrotik()) {
            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', __('servers.ovpn_profile_mikrotik_only'));
        }

        $validated = $request->validate([
            'ovpn_profile' => ['required', 'file', 'max:5120'],
        ]);

        $file = $validated['ovpn_profile'];
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension !== 'ovpn') {
            return redirect()
                ->route('admin.servers.show', $server)
                ->withInput()
                ->with('error', __('servers.ovpn_profile_invalid_extension'));
        }

        try {
            $ovpnProfiles->store($server, $file);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', __('servers.ovpn_profile_uploaded'));
    }

    public function destroyOvpnProfile(
        Server $server,
        ServerOvpnProfileService $ovpnProfiles,
    ): RedirectResponse {
        $this->authorize('update', $server);

        if (! $server->isMikrotik()) {
            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', __('servers.ovpn_profile_mikrotik_only'));
        }

        $ovpnProfiles->delete($server);

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', __('servers.ovpn_profile_deleted'));
    }

    public function storeL2tpIpsec(
        Request $request,
        Server $server,
        ServerL2tpIpsecService $l2tpIpsec,
    ): RedirectResponse {
        $this->authorize('update', $server);

        if (! $server->isMikrotik()) {
            return redirect()
                ->route('admin.servers.show', $server)
                ->with('error', __('servers.l2tp_ipsec_mikrotik_only'));
        }

        $validated = $request->validate([
            'l2tp_ipsec_secret' => ['nullable', 'string', 'max:128'],
            'l2tp_use_ipsec' => ['nullable', 'boolean'],
            'push_to_router' => ['nullable', 'boolean'],
        ]);

        $rawSecret = trim((string) ($validated['l2tp_ipsec_secret'] ?? ''));
        $secretInput = $rawSecret !== '' ? $rawSecret : null;

        try {
            $l2tpIpsec->store(
                $server,
                $secretInput,
                $request->boolean('l2tp_use_ipsec', true),
                $request->boolean('push_to_router', true),
            );
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.servers.show', $server)
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.servers.show', $server)
            ->with('success', __('servers.l2tp_ipsec_saved'));
    }
}
