@php
    $pppProfiles = $pppProfiles ?? collect();
@endphp

<div class="panel-modern-card mb-3">
    <div class="card-head d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h3 class="mb-0">{{ __('servers.ppp_profiles') }}</h3>
        <form method="POST" action="{{ route(admin_server_refresh_router_route(), $server) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-light">
                <i class="bx bx-refresh"></i> {{ __('servers.wireguard_sync') }}
            </button>
        </form>
    </div>
    <div class="card-body">
        <p class="text-muted small">{{ __('servers.ppp_profiles_hint') }}</p>

        <x-table :headers="[
            __('servers.name'),
            __('servers.ppp_subnet'),
            __('servers.ppp_gateway'),
            __('servers.ppp_pool'),
            __('servers.protocol'),
            __('servers.port'),
            __('servers.ppp_secrets_router'),
            __('servers.ppp_accounts_panel'),
            __('servers.speed_limit'),
            __('app.status'),
            __('servers.synced_at'),
        ]">
            @forelse ($pppProfiles as $profile)
                @php
                    $meta = $profile->meta ?? [];
                    $subnet = $meta['subnet'] ?? '—';
                    $gateway = $meta['local_address'] ?? '—';
                    $pool = $meta['pool_name'] ?? $meta['remote_address'] ?? '—';
                    $routerSecrets = $meta['secret_count'] ?? '—';
                    $panelAccounts = $meta['panel_account_count'] ?? '—';
                    $speedMbps = $meta['speed_limit_mbps'] ?? null;
                @endphp
                <tr>
                    <td><code>{{ $profile->name }}</code></td>
                    <td><code>{{ $subnet }}</code></td>
                    <td><code class="small">{{ $gateway }}</code></td>
                    <td><code class="small">{{ $pool }}</code></td>
                    <td>{{ $profile->protocol === 'any' ? __('servers.ppp_protocol_all') : ($profile->protocol ?? '—') }}</td>
                    <td>{{ $profile->port ? persian_digits($profile->port) : '—' }}</td>
                    <td>{{ is_numeric($routerSecrets) ? persian_digits($routerSecrets) : $routerSecrets }}</td>
                    <td>{{ is_numeric($panelAccounts) ? persian_digits($panelAccounts) : $panelAccounts }}</td>
                    <td>
                        @if ($speedMbps && (int) $speedMbps > 0)
                            {{ persian_digits((int) $speedMbps) }} {{ __('servers.speed_limit_mbps_unit') }}
                        @else
                            {{ __('servers.speed_limit_unlimited') }}
                        @endif
                    </td>
                    <td>
                        @if ($profile->is_enabled)
                            <span class="badge bg-success">{{ __('accounts.status_active') }}</span>
                        @else
                            <span class="badge bg-secondary">{{ __('accounts.status_disabled') }}</span>
                        @endif
                    </td>
                    <td>{{ $profile->synced_at ? jalali_date($profile->synced_at) : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="text-center text-muted py-3">
                        {{ __('servers.ppp_profiles_empty') }}
                    </td>
                </tr>
            @endforelse
        </x-table>

        @can('update', $server)
            <hr class="my-4">
            <h6 class="mb-3">{{ __('servers.ppp_create_title') }}</h6>
            <p class="text-muted small">{{ __('servers.ppp_create_hint') }}</p>
            <form method="POST" action="{{ route('admin.servers.ppp-profiles.store', $server) }}" class="row g-3 align-items-end">
                @csrf
                <div class="col-md-2">
                    <label class="form-label" for="ppp-name">{{ __('servers.ppp_profile_name') }}</label>
                    <input type="text" name="name" id="ppp-name" class="form-control form-control-sm"
                           value="{{ old('name') }}" required pattern="[A-Za-z][A-Za-z0-9_-]*"
                           placeholder="ppp-public1">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="ppp-subnet">{{ __('servers.ppp_subnet') }}</label>
                    <input type="text" name="subnet" id="ppp-subnet" class="form-control form-control-sm"
                           value="{{ old('subnet', '10.20.0.0/24') }}" required placeholder="10.20.0.0/24">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="ppp-pool">{{ __('servers.ppp_pool_name') }}</label>
                    <input type="text" name="pool_name" id="ppp-pool" class="form-control form-control-sm"
                           value="{{ old('pool_name') }}" pattern="[A-Za-z][A-Za-z0-9_-]*"
                           placeholder="pool-ppp-public1">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="ppp-protocol">{{ __('servers.protocol') }}</label>
                    <select name="protocol" id="ppp-protocol" class="form-select form-select-sm" required>
                        <option value="any" @selected(old('protocol', 'any') === 'any')>{{ __('servers.ppp_protocol_all') }}</option>
                        <option value="l2tp" @selected(old('protocol') === 'l2tp')>L2TP</option>
                        <option value="ovpn" @selected(old('protocol') === 'ovpn')>OpenVPN</option>
                        <option value="pptp" @selected(old('protocol') === 'pptp')>PPTP</option>
                        <option value="sstp" @selected(old('protocol') === 'sstp')>SSTP</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label d-block">{{ __('servers.ppp_use_encryption') }}</label>
                    <div class="form-check mt-1">
                        <input type="checkbox" name="use_encryption" value="1" id="ppp-encryption"
                               class="form-check-input" @checked(old('use_encryption'))>
                        <label class="form-check-label small" for="ppp-encryption">{{ __('servers.ppp_encryption_yes') }}</label>
                    </div>
                </div>
                @include('admin.servers.partials.speed-limit-select', ['fieldName' => 'speed_limit_mbps'])
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bx bx-plus"></i> {{ __('servers.ppp_create_submit') }}
                    </button>
                </div>
            </form>
        @endcan
    </div>
</div>
