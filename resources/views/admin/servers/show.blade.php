@extends('layouts.panel')

@section('page_title', $server->name)

@section('panel_content')
@if (! \Illuminate\Support\Facades\Schema::hasTable('server_interfaces'))
    <x-alert type="warning" class="mb-3">
        جدول server_interfaces وجود ندارد. از <a href="{{ route('admin.maintenance.index') }}">نگهداری DB</a> migrate را اجرا کنید، یا یک‌بار <a href="/maintain.php">maintain.php</a> را باز کنید.
    </x-alert>
@endif

@include('partials.panel-page-hero', [
    'title' => $server->name,
    'subtitle' => $server->host.':'.persian_digits($server->port).' — '.$server->type->value,
    'icon' => 'bx-server',
    'actions' => '
        <a href="'.route('admin.servers.edit', $server).'" class="btn btn-light btn-sm"><i class="bx bx-edit"></i> '.e(__('app.edit')).'</a>
        '.view('admin.servers.partials.delete-form', ['server' => $server, 'fromShow' => true, 'label' => __('servers.delete'), 'buttonClass' => 'btn-danger btn-sm'])->render().'
        <a href="'.route('admin.servers.index').'" class="btn btn-outline-light btn-sm"><i class="bx bx-arrow-back"></i> '.e(__('app.back')).'</a>
    ',
])

@if (session('operation_log'))
    <div class="panel-modern-card mb-3">
        <div class="card-head"><h3>{{ __('servers.operation_log') }}</h3></div>
        <div class="card-body">
            <ul class="list-unstyled mb-0">
                @foreach ((array) session('operation_log') as $line)
                    @php
                        $lineText = (string) $line;
                        $isError = str_contains($lineText, 'خطا') || str_starts_with($lineText, 'Error');
                    @endphp
                    <li class="mb-1">
                        <i class="bx {{ $isError ? 'bx-error-circle text-danger' : 'bx-check-circle text-success' }}"></i>
                        {{ $lineText }}
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="panel-modern-card h-100">
            <div class="card-head"><h3>{{ __('servers.info') }}</h3></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted">{{ __('servers.host') }}</th>
                            <td><code>{{ $server->host }}:{{ persian_digits($server->port) }}</code></td>
                        </tr>
                        @if ($server->isMikrotik())
                        <tr>
                            <th class="text-muted">{{ __('servers.mikrotik_ssh_port') }}</th>
                            <td>
                                @if ($server->mikrotikSshPort())
                                    <code>{{ persian_digits($server->mikrotikSshPort()) }}</code>
                                @else
                                    <span class="text-warning">{{ __('servers.mikrotik_ssh_port_missing') }}</span>
                                @endif
                            </td>
                        </tr>
                        @endif
                        @if ($server->isPanelBacked() && $server->web_base_path)
                        <tr>
                            <th class="text-muted">{{ __('servers.web_base_path') }}</th>
                            <td><code>{{ $server->web_base_path }}</code></td>
                        </tr>
                        @endif
                        <tr>
                            <th class="text-muted">{{ __('servers.type') }}</th>
                            <td>{{ $server->type->value }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">{{ __('servers.show_in_account_filters') }}</th>
                            <td>
                                @if ($server->show_in_account_filters)
                                    <span class="badge bg-success">{{ __('servers.show_in_account_filters_yes') }}</span>
                                @else
                                    <span class="badge bg-secondary">{{ __('servers.show_in_account_filters_no') }}</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">{{ __('servers.show_on_dashboard') }}</th>
                            <td>
                                @if ($server->show_on_dashboard ?? true)
                                    <span class="badge bg-success">{{ __('servers.show_on_dashboard_yes') }}</span>
                                @else
                                    <span class="badge bg-secondary">{{ __('servers.show_on_dashboard_no') }}</span>
                                @endif
                            </td>
                        </tr>
                        @if ($server->isPasarguard())
                        <tr>
                            <th class="text-muted">{{ __('servers.pasarguard_mode') }}</th>
                            <td>{{ ($server->pasarguard_mode ?? \App\Enums\PasarguardConnectionMode::Reseller)->label() }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">{{ __('servers.pasarguard_groups_synced_at') }}</th>
                            <td>
                                @if ($server->pasarguard_groups_synced_at)
                                    {{ persian_digits($server->pasarguard_groups_synced_at->format('Y-m-d H:i')) }}
                                    — {{ persian_digits(count((array) $server->pasarguard_groups)) }} {{ __('servers.pasarguard_groups') }}
                                @else
                                    <span class="text-warning">{{ __('servers.pasarguard_groups_empty') }}</span>
                                @endif
                            </td>
                        </tr>
                        @endif
                        @if ($server->isRemnawave())
                        <tr>
                            <th class="text-muted">{{ __('servers.remnawave_squads_synced_at') }}</th>
                            <td>
                                @if ($server->remnawave_squads_synced_at)
                                    {{ persian_digits($server->remnawave_squads_synced_at->format('Y-m-d H:i')) }}
                                    — {{ persian_digits(count($server->remnawaveSquadCatalog())) }} squad
                                    — {{ persian_digits(count($server->remnawaveActiveSquadUuids())) }} {{ __('servers.remnawave_squad_active') }}
                                @else
                                    <span class="text-warning">{{ __('servers.remnawave_squads_empty') }}</span>
                                @endif
                            </td>
                        </tr>
                        @endif
                        <tr>
                            <th class="text-muted">{{ __('servers.health') }}</th>
                            <td>{{ $server->last_health_status?->value ?? 'unknown' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">{{ __('servers.accounts') }}</th>
                            <td>{{ persian_digits($accountStats['active']) }} / {{ persian_digits($accountStats['total']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="panel-modern-card h-100">
            <div class="card-head"><h3>{{ __('servers.vpn_operations') }}</h3></div>
            <div class="card-body">
                <p class="text-muted small">
                    @if ($server->isMikrotik())
                        {{ __('servers.operations_hint_mikrotik') }}
                    @elseif ($server->isPasarguard())
                        {{ $server->isPasarguardReseller() ? __('servers.operations_hint_pasarguard_reseller') : __('servers.operations_hint_pasarguard_admin') }}
                    @elseif ($server->isRemnawave())
                        {{ __('servers.operations_hint_remnawave') }}
                    @else
                        {{ __('servers.operations_hint') }}
                    @endif
                </p>
                <div class="panel-action-grid mb-3">
                    <form method="POST" action="{{ route('admin.servers.test-connection', $server) }}" class="d-inline">
                        @csrf
                        <x-button type="submit" size="sm"><i class="bx bx-plug"></i> {{ __('servers.test_connection') }}</x-button>
                    </form>
                    @if ($server->isMikrotik())
                        <form method="POST" action="{{ route('admin.servers.sync-profiles', $server) }}" class="d-inline"
                              onsubmit="return confirm(@json(__('servers.sync_profiles_confirm')))">
                            @csrf
                            <input type="hidden" name="confirmed" value="1">
                            <x-button type="submit" variant="secondary" size="sm"><i class="bx bx-refresh"></i> {{ __('servers.sync_profiles') }}</x-button>
                        </form>
                        <form method="POST" action="{{ route('admin.servers.sync-accounts', $server) }}" class="d-inline"
                              onsubmit="return confirm(@json(__('servers.sync_accounts_confirm')))">
                            @csrf
                            <input type="hidden" name="confirmed" value="1">
                            <x-button type="submit" variant="secondary" size="sm">
                                <i class="bx bx-sync"></i> {{ __('servers.sync_accounts') }}
                            </x-button>
                        </form>
                        <x-button :href="route('admin.servers.import-clients.create', $server)" variant="ghost" size="sm">
                            <i class="bx bx-import"></i> {{ __('servers.import_from_router') }}
                        </x-button>
                    @elseif ($server->isPasarguard())
                        @if ($server->isPasarguardAdmin())
                            <form method="POST" action="{{ route('admin.servers.sync-inbounds', $server) }}" class="d-inline">
                                @csrf
                                <x-button type="submit" variant="secondary" size="sm"><i class="bx bx-refresh"></i> {{ __('servers.sync_inbounds') }}</x-button>
                            </form>
                        @endif
                        <x-button :href="route('admin.servers.import-clients.create', $server)" variant="secondary" size="sm">
                            <i class="bx bx-import"></i> {{ __('servers.sync_users') }}
                        </x-button>
                    @elseif ($server->isRemnawave())
                        <form method="POST" action="{{ route('admin.servers.sync-inbounds', $server) }}" class="d-inline">
                            @csrf
                            <x-button type="submit" variant="secondary" size="sm"><i class="bx bx-refresh"></i> {{ __('servers.sync_remnawave_catalog') }}</x-button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.servers.sync-inbounds', $server) }}" class="d-inline">
                            @csrf
                            <x-button type="submit" variant="secondary" size="sm"><i class="bx bx-refresh"></i> {{ __('servers.sync_inbounds') }}</x-button>
                        </form>
                        <x-button :href="route('admin.servers.import-clients.create', $server)" variant="secondary" size="sm">
                            <i class="bx bx-import"></i> {{ __('servers.import_clients') }}
                        </x-button>
                    @endif
                    <form method="POST" action="{{ route('admin.servers.push-accounts', $server) }}" class="d-inline">
                        @csrf
                        <x-button type="submit" variant="secondary" size="sm"><i class="bx bx-upload"></i> {{ __('servers.push_accounts') }}</x-button>
                    </form>
                    <form method="POST" action="{{ route('admin.servers.push-accounts', $server) }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="only_missing" value="1">
                        <x-button type="submit" variant="ghost" size="sm">{{ __('servers.push_accounts_missing') }}</x-button>
                    </form>
                    <form method="POST" action="{{ route('admin.servers.sync-traffic', $server) }}" class="d-inline">
                        @csrf
                        <x-button type="submit" variant="ghost" size="sm"><i class="bx bx-transfer"></i> {{ __('servers.sync_traffic') }}</x-button>
                    </form>
                    @if ($server->isSanaei() && ($sanaeiServers ?? collect())->isNotEmpty())
                        <form method="POST" action="{{ route('admin.servers.migrate-sanaei', $server) }}" class="d-inline"
                              onsubmit="return confirm(@json(__('servers.migrate_sanaei_confirm')));">
                            @csrf
                            <select name="from_server_id" class="form-select form-select-sm d-inline-block w-auto" required>
                                @foreach ($sanaeiServers as $src)
                                    <option value="{{ $src->id }}">{{ $src->name }} ({{ $src->host }})</option>
                                @endforeach
                            </select>
                            <x-button type="submit" variant="warning" size="sm">
                                <i class="bx bx-transfer-alt"></i> {{ __('servers.migrate_sanaei_here') }}
                            </x-button>
                        </form>
                    @endif
                </div>
                <p class="text-muted small mb-0">{{ __('servers.volume_note') }}</p>
                @if ($server->isSanaei())
                    <p class="text-muted small mb-0 mt-1">{{ __('servers.migrate_sanaei_hint') }}</p>
                @endif
            </div>
        </div>
    </div>
</div>

@if ($server->isRemnawave())
<div class="panel-modern-card mb-3">
    <div class="card-head d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h3 class="mb-0">{{ __('servers.remnawave_active_squads') }}</h3>
        <a href="{{ route('admin.servers.edit', $server) }}" class="btn btn-sm btn-light">{{ __('app.edit') }}</a>
    </div>
    <div class="card-body">
        <p class="text-muted small">{{ __('servers.remnawave_active_squads_hint') }}</p>
        @php
            $activeSet = array_fill_keys($server->remnawaveActiveSquadUuids(), true);
        @endphp
        <x-table :headers="['UUID', __('servers.name'), __('servers.status')]">
            @forelse ($server->remnawaveSquadCatalog() as $squad)
                <tr>
                    <td><code class="small">{{ $squad['uuid'] }}</code></td>
                    <td>{{ $squad['name'] }}</td>
                    <td>
                        @if (isset($activeSet[$squad['uuid']]))
                            <span class="badge bg-success">{{ __('servers.remnawave_squad_active') }}</span>
                        @else
                            <span class="badge bg-secondary">{{ __('servers.remnawave_squad_inactive') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center text-muted">{{ __('servers.remnawave_squads_empty') }}</td></tr>
            @endforelse
        </x-table>
    </div>
</div>
@endif

@if ($server->isPasarguard())
<div class="panel-modern-card mb-3">
    <div class="card-head"><h3>{{ __('servers.pasarguard_groups') }}</h3></div>
    <div class="card-body">
        <p class="text-muted small">{{ __('packages.pasarguard_groups_from_server_cache') }}</p>
        <x-table :headers="['ID', __('servers.name'), __('servers.pasarguard_group_inbound_tags')]">
            @forelse ((array) $server->pasarguard_groups as $group)
                <tr>
                    <td><code>{{ persian_digits($group['id'] ?? '') }}</code></td>
                    <td>{{ $group['name'] ?? '—' }}</td>
                    <td><small>{{ implode(', ', $group['inbound_tags'] ?? []) ?: '—' }}</small></td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center text-muted">{{ __('servers.pasarguard_groups_empty') }}</td></tr>
            @endforelse
        </x-table>
    </div>
</div>
@endif

@if ($server->isMikrotik())
    @include('admin.servers.partials.l2tp-ipsec', ['server' => $server])
    @include('admin.servers.partials.ovpn-profile', ['server' => $server])
    @include('admin.servers.partials.ppp-profiles', ['server' => $server, 'pppProfiles' => $pppProfiles ?? collect()])
    @include('admin.servers.partials.wireguard-interfaces', ['server' => $server, 'wireguardInterfaces' => $wireguardInterfaces ?? collect()])
@endif

@if (! $server->isRemnawave() && ! $server->isMikrotik())
<div class="panel-modern-card mb-3">
    <div class="card-head"><h3>{{ $server->isMikrotik() ? __('servers.cached_profiles') : __('servers.cached_interfaces') }}</h3></div>
    <div class="card-body">
        <x-table :headers="[__('servers.name'), __('servers.category'), __('servers.protocol'), __('servers.port'), __('servers.synced_at')]">
            @forelse ($interfaces as $iface)
                <tr>
                    <td>{{ $iface->name }}</td>
                    <td>{{ $server->isMikrotik() ? server_interface_type_label($iface) : $iface->category }}</td>
                    <td>{{ $iface->protocol ?? '—' }}</td>
                    <td>{{ $iface->port ? persian_digits($iface->port) : '—' }}</td>
                    <td>{{ $iface->synced_at ? jalali_date($iface->synced_at) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted">{{ $server->isMikrotik() ? __('servers.no_profiles') : __('servers.no_interfaces') }}</td></tr>
            @endforelse
        </x-table>
    </div>
</div>
@endif

<div class="panel-modern-card">
    <div class="card-head"><h3>{{ __('servers.recent_sync_logs') }}</h3></div>
    <div class="card-body">
        <x-table :headers="[__('servers.started'), __('servers.status'), __('servers.accounts_synced'), __('servers.errors')]">
            @forelse ($server->syncLogs as $log)
                <tr>
                    <td>{{ jalali_date($log->started_at) }}</td>
                    <td>{{ $log->status->value }}</td>
                    <td>{{ persian_digits($log->accounts_synced) }}</td>
                    <td>{{ persian_digits($log->errors_count) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted">{{ __('app.no_results') }}</td></tr>
            @endforelse
        </x-table>
    </div>
</div>
@endsection
