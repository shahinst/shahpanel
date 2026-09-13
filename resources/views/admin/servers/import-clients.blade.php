@extends('layouts.panel')

@section('page_title', __('servers.import_clients').' — '.$server->name)

@section('panel_content')
<p class="btn-toolbar margin-bottom">
    <x-button :href="route('admin.servers.show', $server)" variant="ghost">{{ __('app.back') }}</x-button>
</p>

@if ($server->isMikrotik())
    <x-card :title="__('servers.import_wireguard_clients')">
        <p class="help-block">{{ __('servers.import_wireguard_clients_hint') }}</p>
        <form method="POST" action="{{ route('admin.servers.import-clients.preview', $server) }}">
            @csrf
            <input type="hidden" name="import_type" value="wireguard">
            <x-form.actions>
                <x-button type="submit"><i class="bx bx-import"></i> {{ __('servers.import_fetch_clients') }}</x-button>
            </x-form.actions>
        </form>
    </x-card>

    <x-card :title="__('servers.import_select_ppp_profile')" class="mt-3">
        <p class="help-block">{{ __('servers.import_select_ppp_profile_hint') }}</p>

        @if (($profiles ?? collect())->isEmpty())
            <x-alert type="warning">
                {{ __('servers.import_no_ppp_profiles') }}
                <form method="POST" action="{{ route(admin_server_refresh_router_route(), $server) }}" class="d-inline">
                    @csrf
                    <x-button type="submit" variant="secondary" class="ms-2">{{ __('servers.wireguard_sync') }}</x-button>
                </form>
            </x-alert>
        @else
            <form method="POST" action="{{ route('admin.servers.import-clients.preview', $server) }}">
                @csrf
                <x-form.group label="{{ __('servers.ppp_profile') }}">
                    <select name="profile_key" class="form-control" required>
                        <option value="">{{ __('servers.import_choose_profile') }}</option>
                        @foreach ($profiles as $profile)
                            <option value="{{ $profile->remote_key }}" @selected(old('profile_key') === $profile->remote_key)>
                                {{ $profile->name }}
                                — {{ server_interface_type_label($profile) }}
                                @if ($profile->port)
                                    ({{ persian_digits($profile->port) }})
                                @endif
                            </option>
                        @endforeach
                    </select>
                </x-form.group>
                <x-form.actions>
                    <x-button type="submit">{{ __('servers.import_fetch_clients') }}</x-button>
                </x-form.actions>
            </form>
        @endif
    </x-card>
@elseif ($server->isPasarguard())
    <x-card :title="__('servers.pasarguard_sync_users')">
        <p class="help-block">{{ __('servers.pasarguard_sync_users_hint') }}</p>
        <form method="POST" action="{{ route('admin.servers.import-clients.preview', $server) }}">
            @csrf
            <x-form.actions>
                <x-button type="submit"><i class="bx bx-import"></i> {{ __('servers.import_fetch_clients') }}</x-button>
            </x-form.actions>
        </form>
    </x-card>
@else
    <x-card :title="__('servers.import_select_inbound')">
        <p class="help-block">{{ __('servers.import_select_inbound_hint') }}</p>

        @if (($inbounds ?? collect())->isEmpty())
            <x-alert type="warning">
                {{ __('servers.import_no_inbounds') }}
                <form method="POST" action="{{ route('admin.servers.sync-inbounds', $server) }}" class="d-inline">
                    @csrf
                    <x-button type="submit" variant="secondary" class="ms-2">{{ __('servers.sync_inbounds') }}</x-button>
                </form>
            </x-alert>
        @else
            <form method="POST" action="{{ route('admin.servers.import-clients.preview', $server) }}">
                @csrf
                <x-form.group label="{{ __('servers.inbound') }}">
                    <select name="inbound_id" class="form-control" required>
                        <option value="">{{ __('servers.import_choose_inbound') }}</option>
                        @foreach ($inbounds as $iface)
                            @php
                                $inboundId = (int) str_replace('inbound:', '', $iface->remote_key);
                            @endphp
                            <option value="{{ $inboundId }}" @selected(old('inbound_id') == $inboundId)>
                                {{ $iface->name }}
                                @if ($iface->protocol)
                                    ({{ $iface->protocol }}@if($iface->port) — {{ persian_digits($iface->port) }}@endif)
                                @endif
                                — #{{ persian_digits($inboundId) }}
                            </option>
                        @endforeach
                    </select>
                </x-form.group>
                <x-form.actions>
                    <x-button type="submit">{{ __('servers.import_fetch_clients') }}</x-button>
                </x-form.actions>
            </form>
        @endif
    </x-card>
@endif
@endsection
