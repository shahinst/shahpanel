<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PasarguardConnectionMode;
use App\Enums\ServerHealthStatus;
use App\Enums\ServerType;
use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Remnawave\RemnawavePanelClient;
use App\Services\RemnawaveService;
use App\Services\ServerInterfaceSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServerController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Server::class);

        $servers = Server::query()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('host', 'like', "%{$search}%");
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.servers.index', compact('servers'));
    }

    public function show(Server $server): View
    {
        $this->authorize('view', $server);

        $server->load(['syncLogs' => fn ($q) => $q->latest('started_at')->limit(10)]);

        $interfaces = Schema::hasTable('server_interfaces')
            ? $server->interfaces()
                ->when($server->isMikrotik(), fn ($q) => $q->where('category', 'ppp'))
                ->when($server->isRemnawave(), fn ($q) => $q->whereRaw('0 = 1'))
                ->when($server->isPanelBacked() && ! $server->isMikrotik() && ! $server->isRemnawave(), fn ($q) => $q->where('category', 'inbound'))
                ->orderBy('category')
                ->orderBy('name')
                ->get()
            : collect();

        $pppProfiles = Schema::hasTable('server_interfaces') && $server->isMikrotik()
            ? $server->interfaces()
                ->where(function ($query) {
                    $query->where('category', 'ppp')
                        ->orWhere('remote_key', 'like', 'profile:ppp:%');
                })
                ->orderBy('name')
                ->get()
            : collect();

        $wireguardInterfaces = Schema::hasTable('server_interfaces') && $server->isMikrotik()
            ? $server->interfaces()
                ->where(function ($query) {
                    $query->where('category', 'wireguard')
                        ->orWhere('remote_key', 'like', 'profile:wg:%')
                        ->orWhere('remote_key', 'like', 'wg:%');
                })
                ->orderBy('name')
                ->get()
            : collect();

        $accountStats = [
            'total' => $server->accounts()->count(),
            'active' => $server->accounts()->where('status', AccountStatus::Active)->count(),
        ];

        $sanaeiServers = $server->isSanaei()
            ? Server::query()
                ->where('type', $server->type)
                ->where('id', '!=', $server->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'host'])
            : collect();

        return view('admin.servers.show', compact('server', 'accountStats', 'interfaces', 'pppProfiles', 'wireguardInterfaces', 'sanaeiServers'));
    }

    public function create(): View
    {
        $this->authorize('create', Server::class);

        return view('admin.servers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Server::class);

        $validated = $this->validatedData($request);
        $server = Server::query()->create($validated);

        if ($server->isRemnawave() && $server->api_token_enc) {
            RemnawavePanelClient::clearAuthCacheForServer($server->id);
        }

        if ($server->isRemnawave() && $request->boolean('remnawave_sync_after_save')) {
            return $this->redirectAfterServerSaveWithRemnawaveSync($request, $server->fresh(), created: true);
        }

        return $this->redirectAfterServerSave($request, $server, created: true);
    }

    public function edit(Server $server): View
    {
        $this->authorize('update', $server);

        return view('admin.servers.edit', compact('server'));
    }

    public function update(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('update', $server);

        $payload = $this->validatedData($request, $server);
        $tokenUpdated = array_key_exists('api_token_enc', $payload);
        $server->update($payload);

        if ($server->isRemnawave() && $tokenUpdated) {
            RemnawavePanelClient::clearAuthCacheForServer($server->id);
        }

        if ($server->isRemnawave() && $request->boolean('remnawave_sync_after_save')) {
            return $this->redirectAfterServerSaveWithRemnawaveSync($request, $server->fresh(), created: false);
        }

        return $this->redirectAfterServerSave($request, $server->fresh(), created: false);
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('delete', $server);

        $redirect = $request->boolean('from_show')
            ? route('admin.servers.show', $server)
            : route('admin.servers.index');

        try {
            $this->assertServerCanBeDeleted($server);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? __('servers.delete_blocked');

            return redirect($redirect)->with('error', $message);
        }

        $server->interfaces()->delete();
        $server->syncLogs()->delete();
        $server->packages()->detach();
        $server->delete();

        return redirect()
            ->route('admin.servers.index')
            ->with('success', __('servers.deleted'));
    }

    protected function assertServerCanBeDeleted(Server $server): void
    {
        $accounts = $server->accounts()->count();

        if ($accounts > 0) {
            throw ValidationException::withMessages([
                'server' => [__('servers.delete_has_accounts', ['count' => $accounts])],
            ]);
        }

        if (Schema::hasTable('account_server_changes')) {
            $hasHistory = DB::table('account_server_changes')
                ->where('old_server_id', $server->id)
                ->orWhere('new_server_id', $server->id)
                ->exists();

            if ($hasHistory) {
                throw ValidationException::withMessages([
                    'server' => [__('servers.delete_has_transfer_history')],
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedData(Request $request, ?Server $server = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ServerType::class)],
            'api_token' => ['nullable', 'string', 'max:2000'],
            'mikrotik_api_token' => ['nullable', 'string', 'max:2000'],
            'pasarguard_mode' => ['nullable', Rule::enum(PasarguardConnectionMode::class)],
            'role' => ['nullable', 'in:internal,external'],
            'host' => ['required', 'string', 'max:255'],
            'public_ip' => ['nullable', 'string', 'max:45'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'web_base_path' => ['nullable', 'string', 'max:255'],
            'sanaei_verify_ssl' => ['sometimes', 'boolean'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'remnawave_api_key' => ['nullable', 'string', 'max:2000'],
            'is_public' => ['sometimes', 'boolean'],
            'max_accounts' => ['nullable', 'integer', 'min:0'],
            'wireguard_persistent_keepalive' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'is_active' => ['sometimes', 'boolean'],
            'show_in_account_filters' => ['sometimes', 'boolean'],
            'show_on_dashboard' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'remnawave_active_squads' => ['nullable', 'array'],
            'remnawave_active_squads.*' => ['string', 'max:64'],
            'remnawave_sync_after_save' => ['sometimes', 'boolean'],
            'cisco_vpn_hostname' => ['nullable', 'string', 'max:255'],
            'cisco_group_policy' => ['nullable', 'string', 'max:128'],
            'cisco_tunnel_group' => ['nullable', 'string', 'max:128'],
            'cisco_simultaneous_logins' => ['nullable', 'integer', 'min:0', 'max:100'],
            'cisco_verify_ssl' => ['sometimes', 'boolean'],
            'cisco_write_memory' => ['sometimes', 'boolean'],
        ]);

        $validated = $this->mergeApiTokenFromRequest($request, $validated);
        $validated = $this->normalizeRemnawaveCredentials($validated);
        $this->assertMikrotikCredentials($validated, $server);

        $pasarguardMode = $this->resolvePasarguardModeValue($validated['type'], $validated['pasarguard_mode'] ?? null);

        $payload = [
            'name' => $validated['name'],
            'location' => $validated['location'] ?? null,
            'type' => $validated['type'],
            'role' => $validated['role'] ?? 'internal',
            'host' => $validated['host'],
            'public_ip' => isset($validated['public_ip']) && trim((string) $validated['public_ip']) !== ''
                ? trim((string) $validated['public_ip'])
                : null,
            'port' => $validated['port'],
            'web_base_path' => isset($validated['web_base_path']) && $validated['web_base_path'] !== ''
                ? '/'.trim($validated['web_base_path'], '/')
                : null,
            'is_public' => $request->boolean('is_public'),
            'max_accounts' => $validated['max_accounts'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'show_in_account_filters' => $request->boolean('show_in_account_filters'),
            'show_on_dashboard' => $request->boolean('show_on_dashboard'),
            'notes' => $validated['notes'] ?? null,
            'last_health_status' => $server?->last_health_status ?? ServerHealthStatus::Unknown,
        ];

        // ستون pasarguard_mode NOT NULL است — فقط برای نوع PasarGuard مقدار بفرستید.
        if ($pasarguardMode !== null) {
            $payload['pasarguard_mode'] = $pasarguardMode;
        }

        if (! empty($validated['username'])) {
            $payload['username_enc'] = $validated['username'];
        }

        if (! empty($validated['password'])) {
            $payload['password_enc'] = $validated['password'];
        }

        if (! empty($validated['api_token'])) {
            $payload['api_token_enc'] = $validated['api_token'];
        }

        if (! empty($validated['remnawave_api_key'])) {
            $payload['remnawave_api_key_enc'] = $validated['remnawave_api_key'];
        }

        $typeValue = $payload['type'] instanceof ServerType
            ? $payload['type']->value
            : (string) ($payload['type'] ?? '');

        if ($typeValue === ServerType::Mikrotik->value) {
            $payload['wireguard_persistent_keepalive'] = isset($validated['wireguard_persistent_keepalive'])
                ? (int) $validated['wireguard_persistent_keepalive']
                : 10;
            $payload['ssh_port'] = isset($validated['ssh_port']) && $validated['ssh_port'] !== null
                ? (int) $validated['ssh_port']
                : null;
        } elseif ($server !== null) {
            $payload['wireguard_persistent_keepalive'] = null;
            $payload['ssh_port'] = null;
        }

        if ($typeValue === ServerType::Remnawave->value) {
            $activeSquads = $this->normalizeRemnawaveActiveSquads(
                $server,
                $validated['remnawave_active_squads'] ?? null,
            );
            $payload['remnawave_active_squads'] = $activeSquads;

            if ($server !== null && $server->remnawaveSquadCatalog() !== [] && $activeSquads === []) {
                throw ValidationException::withMessages([
                    'remnawave_active_squads' => [__('servers.remnawave_active_squads_required')],
                ]);
            }
        }

        if ($typeValue === ServerType::CiscoAnyconnect->value) {
            $payload['cisco_vpn_hostname'] = isset($validated['cisco_vpn_hostname']) && trim((string) $validated['cisco_vpn_hostname']) !== ''
                ? trim((string) $validated['cisco_vpn_hostname'])
                : null;
            $payload['cisco_group_policy'] = isset($validated['cisco_group_policy']) && trim((string) $validated['cisco_group_policy']) !== ''
                ? trim((string) $validated['cisco_group_policy'])
                : null;
            $payload['cisco_tunnel_group'] = isset($validated['cisco_tunnel_group']) && trim((string) $validated['cisco_tunnel_group']) !== ''
                ? trim((string) $validated['cisco_tunnel_group'])
                : null;
            $payload['cisco_simultaneous_logins'] = isset($validated['cisco_simultaneous_logins'])
                ? (int) $validated['cisco_simultaneous_logins']
                : 1;
            $payload['cisco_verify_ssl'] = $request->boolean('cisco_verify_ssl');
            $payload['cisco_write_memory'] = $request->boolean('cisco_write_memory', true);

            if (empty($validated['username']) && ($server === null || blank($server->username_enc))) {
                throw ValidationException::withMessages([
                    'username' => [__('servers.cisco_admin_username_required')],
                ]);
            }
            if (empty($validated['password']) && ($server === null || blank($server->password_enc))) {
                throw ValidationException::withMessages([
                    'password' => [__('servers.cisco_admin_password_required')],
                ]);
            }
        }

        // Only the panel form renders this toggle; other server types keep theirs.
        if ($request->has('sanaei_verify_ssl')) {
            $payload['sanaei_verify_ssl'] = $request->boolean('sanaei_verify_ssl');
        }

        if (! Schema::hasColumn('servers', 'ssh_port')) {
            unset($payload['ssh_port']);
        }

        if (! Schema::hasColumn('servers', 'sanaei_verify_ssl')) {
            unset($payload['sanaei_verify_ssl']);
        }

        return $payload;
    }

    /**
     * @param  list<string>|null  $submitted
     * @return list<string>
     */
    protected function normalizeRemnawaveActiveSquads(?Server $server, ?array $submitted): array
    {
        if (! is_array($submitted)) {
            return $server?->remnawaveActiveSquadUuids() ?? [];
        }

        $catalogUuids = $server !== null
            ? array_column($server->remnawaveSquadCatalog(), 'uuid')
            : [];

        $picked = array_values(array_unique(array_filter(array_map('strval', $submitted), fn (string $v): bool => $v !== '')));

        if ($catalogUuids === []) {
            return $picked;
        }

        return array_values(array_intersect($picked, $catalogUuids));
    }

    protected function redirectAfterServerSave(Request $request, Server $server, bool $created): RedirectResponse
    {
        $redirect = $created
            ? redirect()->route('admin.servers.edit', $server)
            : redirect()->route('admin.servers.edit', $server);

        return $redirect->with('success', __('app.saved'));
    }

    protected function redirectAfterServerSaveWithRemnawaveSync(
        Request $request,
        Server $server,
        bool $created,
    ): RedirectResponse {
        $redirect = redirect()->route('admin.servers.edit', $server)->with('success', __('app.saved'));

        if (! $server->hasStoredRemnawaveApiToken()) {
            return $redirect->with('warning', __('servers.remnawave_api_token_missing_edit'));
        }

        try {
            $result = app(RemnawaveService::class)->syncCatalog($server);
            $lines = $result['lines'];

            if ($result['squads'] > 0) {
                return $redirect
                    ->with('success', __('servers.remnawave_sync_done', [
                        'squads' => persian_digits($result['squads']),
                        'nodes' => persian_digits($result['nodes']),
                    ]))
                    ->with('operation_log', $lines);
            }

            return $redirect
                ->with('warning', __('servers.remnawave_sync_failed', [
                    'error' => implode(' — ', $result['errors']) ?: __('servers.remnawave_sync_catalog_failed'),
                ]))
                ->with('operation_log', array_merge($lines, $result['errors']));
        } catch (\Throwable $exception) {
            return $redirect->with('warning', __('servers.remnawave_sync_failed', [
                'error' => $exception->getMessage(),
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function mergeApiTokenFromRequest(Request $request, array $validated): array
    {
        $type = $validated['type'] instanceof ServerType
            ? $validated['type']->value
            : (string) ($validated['type'] ?? '');

        if ($type === ServerType::Mikrotik->value) {
            $mikrotikToken = trim((string) ($validated['mikrotik_api_token'] ?? ''));
            if ($mikrotikToken !== '') {
                $validated['api_token'] = $mikrotikToken;
            }
        } else {
            $validated['api_token'] = $this->resolveSubmittedApiToken($request);
        }

        unset($validated['mikrotik_api_token']);

        return $validated;
    }

    protected function resolveSubmittedApiToken(Request $request): ?string
    {
        $raw = $request->input('api_token');

        if (is_array($raw)) {
            $parts = array_values(array_filter(array_map(
                fn ($v) => trim((string) $v),
                $raw,
            ), fn (string $v): bool => $v !== ''));

            return $parts === [] ? null : (string) end($parts);
        }

        $token = trim((string) ($raw ?? ''));

        return $token === '' ? null : $token;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function normalizeRemnawaveCredentials(array $validated): array
    {
        $type = $validated['type'] instanceof ServerType
            ? $validated['type']->value
            : (string) ($validated['type'] ?? '');

        if ($type !== ServerType::Remnawave->value) {
            return $validated;
        }

        $validated['host'] = $this->normalizeRemnawaveHost((string) ($validated['host'] ?? ''));

        $apiToken = trim((string) ($validated['api_token'] ?? ''));
        $altKey = trim((string) ($validated['remnawave_api_key'] ?? ''));

        if ($apiToken === '' && $altKey !== '' && str_starts_with($altKey, 'eyJ')) {
            $validated['api_token'] = $altKey;
            $validated['remnawave_api_key'] = null;
        }

        return $validated;
    }

    protected function normalizeRemnawaveHost(string $host): string
    {
        $host = trim($host);
        $host = preg_replace('#(/api)+/?$#i', '', $host) ?? $host;

        return rtrim($host, '/');
    }

    protected function resolvePasarguardModeValue(mixed $type, mixed $mode): ?string
    {
        $typeValue = $type instanceof ServerType ? $type->value : (string) $type;

        if ($typeValue !== ServerType::Pasarguard->value) {
            return null;
        }

        if ($mode instanceof PasarguardConnectionMode) {
            return $mode->value;
        }

        return PasarguardConnectionMode::tryFrom((string) ($mode ?? ''))?->value
            ?? PasarguardConnectionMode::Reseller->value;
    }

    /**
     * MikroTik connects via RouterOS username/password (not panel token).
     *
     * @param  array<string, mixed>  $validated
     */
    protected function assertMikrotikCredentials(array $validated, ?Server $server): void
    {
        $type = $validated['type'] instanceof ServerType
            ? $validated['type']
            : ServerType::tryFrom((string) ($validated['type'] ?? ''));

        if ($type !== ServerType::Mikrotik) {
            return;
        }

        $username = trim((string) ($validated['username'] ?? ''));
        $password = trim((string) ($validated['password'] ?? ''));

        if ($server === null) {
            $errors = [];
            if ($username === '') {
                $errors['username'] = [__('servers.mikrotik_username_required')];
            }
            if ($password === '') {
                $errors['password'] = [__('servers.mikrotik_password_required')];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return;
        }

        // Editing: allow partial updates, but both fields must be present eventually.
        if ($username === '' && ($server->username_enc === null || $server->username_enc === '')) {
            throw ValidationException::withMessages([
                'username' => [__('servers.mikrotik_username_required')],
            ]);
        }

        if ($password === '' && ($server->password_enc === null || $server->password_enc === '')) {
            throw ValidationException::withMessages([
                'password' => [__('servers.mikrotik_password_required')],
            ]);
        }
    }
}
