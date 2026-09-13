@php
    $wireguardInterfaces = $wireguardInterfaces ?? collect();
@endphp

<div class="panel-modern-card mb-3">
    <div class="card-head d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h3 class="mb-0">{{ __('servers.wireguard_interfaces') }}</h3>
        <form method="POST" action="{{ route(admin_server_refresh_router_route(), $server) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-light">
                <i class="bx bx-refresh"></i> {{ __('servers.wireguard_sync') }}
            </button>
        </form>
    </div>
    <div class="card-body">
        <p class="text-muted small">{{ __('servers.wireguard_interfaces_hint') }}</p>
        <p class="text-muted small mb-2">{{ __('servers.wireguard_row_update_hint') }}</p>

        <x-table :headers="[
            __('servers.name'),
            __('servers.wireguard_subnet'),
            __('servers.wireguard_gateway'),
            __('servers.port'),
            __('servers.wireguard_peers_router'),
            __('servers.wireguard_peers_panel'),
            __('servers.speed_limit'),
            __('app.status'),
            __('servers.synced_at'),
            __('servers.row_update'),
        ]">
            @forelse ($wireguardInterfaces as $iface)
                @php
                    $meta = $iface->meta ?? [];
                    $subnet = $meta['subnet'] ?? '—';
                    $gateway = $meta['gateway'] ?? '—';
                    $peerRouter = $meta['peer_count'] ?? '—';
                    $peerPanel = $meta['panel_peer_count'] ?? '—';
                    $speedMbps = $meta['speed_limit_mbps'] ?? null;
                    $formId = 'wg-update-'.$iface->id;
                @endphp
                <tr>
                    @can('update', $server)
                        <td>
                            <input type="text" name="name" form="{{ $formId }}"
                                   class="form-control form-control-sm" required
                                   pattern="[A-Za-z][A-Za-z0-9_-]*"
                                   value="{{ old('name.'.$iface->id, $iface->name) }}">
                        </td>
                    @else
                        <td><code>{{ $iface->name }}</code></td>
                    @endcan
                    <td><code>{{ $subnet }}</code></td>
                    <td><code class="small">{{ $gateway }}</code></td>
                    <td>{{ $iface->port ? persian_digits($iface->port) : '—' }}</td>
                    <td>{{ is_numeric($peerRouter) ? persian_digits($peerRouter) : $peerRouter }}</td>
                    <td>{{ is_numeric($peerPanel) ? persian_digits($peerPanel) : $peerPanel }}</td>
                    <td>
                        @can('update', $server)
                            @include('admin.servers.partials.speed-limit-select', [
                                'fieldName' => 'speed_limit_mbps',
                                'selected' => old('speed_limit_mbps.'.$iface->id, $speedMbps),
                                'inputId' => 'wg-speed-'.$iface->id,
                                'formId' => $formId,
                            ])
                        @else
                            @if ($speedMbps && (int) $speedMbps > 0)
                                {{ persian_digits((int) $speedMbps) }} {{ __('servers.speed_limit_mbps_unit') }}
                            @else
                                {{ __('servers.speed_limit_unlimited') }}
                            @endif
                        @endcan
                    </td>
                    <td>
                        @can('update', $server)
                            <select name="is_enabled" form="{{ $formId }}" class="form-select form-select-sm">
                                <option value="1" @selected($iface->is_enabled)>
                                    {{ __('accounts.status_active') }}
                                </option>
                                <option value="0" @selected(! $iface->is_enabled)>
                                    {{ __('accounts.status_disabled') }}
                                </option>
                            </select>
                        @else
                            @if ($iface->is_enabled)
                                <span class="badge bg-success">{{ __('accounts.status_active') }}</span>
                            @else
                                <span class="badge bg-secondary">{{ __('accounts.status_disabled') }}</span>
                            @endif
                        @endcan
                    </td>
                    <td>{{ $iface->synced_at ? jalali_date($iface->synced_at) : '—' }}</td>
                    <td>
                        @can('update', $server)
                            <form method="POST" id="{{ $formId }}"
                                  action="{{ route('admin.servers.wireguard-interfaces.update', [$server, $iface]) }}">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="bx bx-save"></i> {{ __('servers.row_update') }}
                                </button>
                            </form>
                        @else
                            —
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center text-muted py-3">
                        {{ __('servers.wireguard_interfaces_empty') }}
                    </td>
                </tr>
            @endforelse
        </x-table>

        @can('update', $server)
            <hr class="my-4">
            <h6 class="mb-3">{{ __('servers.wireguard_create_title') }}</h6>
            <p class="text-muted small">{{ __('servers.wireguard_create_hint') }}</p>
            <form method="POST" action="{{ route('admin.servers.wireguard-interfaces.store', $server) }}" class="row g-3 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="wg-name">{{ __('servers.wireguard_interface_name') }}</label>
                    <input type="text" name="name" id="wg-name" class="form-control form-control-sm"
                           value="{{ old('name') }}" required pattern="[A-Za-z][A-Za-z0-9_-]*"
                           placeholder="wg-clients-2">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="wg-subnet">{{ __('servers.wireguard_subnet') }}</label>
                    <input type="text" name="subnet" id="wg-subnet" class="form-control form-control-sm"
                           value="{{ old('subnet', '10.10.1.0/24') }}" required
                           placeholder="10.10.1.0/24">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="wg-port">{{ __('servers.wireguard_listen_port') }}</label>
                    <input type="number" name="listen_port" id="wg-port" class="form-control form-control-sm"
                           value="{{ old('listen_port') }}" min="1" max="65535" placeholder="51820">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="speed_limit_mbps">{{ __('servers.speed_limit') }}</label>
                    @include('admin.servers.partials.speed-limit-select', ['fieldName' => 'speed_limit_mbps', 'inputId' => 'speed_limit_mbps'])
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bx bx-plus"></i> {{ __('servers.wireguard_create_submit') }}
                    </button>
                </div>
            </form>
        @endcan
    </div>
</div>
