@extends('layouts.panel')

@section('page_title', __('dedicated::admin.menu'))

@section('panel_content')
<x-page-header :title="__('dedicated::admin.menu')" :subtitle="__('dedicated::admin.admin_intro')">
    <x-slot:actions>
        <x-button :href="route('admin.dedicated.create')">
            <i class="bx bx-plus align-middle"></i> {{ __('dedicated::admin.create_agent') }}
        </x-button>
    </x-slot:actions>
</x-page-header>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <form method="GET" class="form-inline" style="margin-bottom:15px;">
                    <div class="form-group">
                        <input type="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('app.search') }}">
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm">{{ __('app.search') }}</button>
                </form>

                <x-table :headers="[
                    __('auth.username'),
                    __('dedicated::admin.servers_col'),
                    __('dedicated::admin.usage_total'),
                    __('dedicated::admin.usage_today'),
                    __('dedicated::admin.dash_accounts'),
                    __('wallet.remaining_balance'),
                    __('app.status'),
                    __('app.actions'),
                ]">
                    @forelse ($agents as $agent)
                        @php
                            $own = $servers->get($agent->id, collect());
                            $total = (int) $own->sum('total_rx_bytes');
                            $todayRx = (int) $own->sum(fn ($row) => (int) ($today[$row->server_id] ?? 0));
                            $accountCount = (int) $own->sum(fn ($row) => (int) ($accounts[$row->server_id] ?? 0));
                            $broken = $own->filter(fn ($row) => filled($row->last_error))->count();
                        @endphp
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $agent->username }}</div>
                                @if ($agent->full_name)<div class="text-muted small">{{ $agent->full_name }}</div>@endif
                            </td>
                            <td>
                                @foreach ($own as $row)
                                    <div class="small">
                                        <i class="bx bx-server text-muted"></i> {{ $row->server?->name }}
                                        <span class="text-muted">· {{ $row->server?->type?->value }}</span>
                                        @if ($row->meter_interface)
                                            <span class="badge bg-light text-dark" dir="ltr">{{ $row->meter_interface }}</span>
                                        @endif
                                    </div>
                                @endforeach
                                @if ($broken > 0)
                                    <div class="text-danger small"><i class="bx bx-error"></i> {{ __('dedicated::admin.meter_errors', ['count' => $broken]) }}</div>
                                @endif
                            </td>
                            <td dir="ltr">{{ format_data_size($total) }}</td>
                            <td dir="ltr">{{ format_data_size($todayRx) }}</td>
                            <td>{{ $accountCount }}</td>
                            <td>
                                @forelse ($agent->wallets as $walletRow)
                                    {{ format_money($walletRow->balance, $walletRow->currency) }}@if (! $loop->last)<br>@endif
                                @empty
                                    {{ format_money(0, \App\Enums\MoneyCurrency::IRT) }}
                                @endforelse
                            </td>
                            <td>{{ $agent->status->value }}</td>
                            <td class="text-nowrap">
                                <div class="icon-actions">
                                    <x-icon-action :href="route('admin.dedicated.edit', $agent)" icon="bx-cog" variant="primary" :label="__('dedicated::admin.agent_settings')" />
                                    <x-icon-action :href="route('admin.users.edit', $agent)" icon="bx-edit" variant="secondary" :label="__('app.edit')" />
                                    @can('impersonate', $agent)
                                        <x-icon-action :action="route('admin.users.impersonate', $agent)" icon="bx-log-in-circle" variant="info" :label="__('security.impersonation_as')" />
                                    @endcan
                                    @include('shared.users.delete-button', ['deleteUser' => $agent, 'deleteRoute' => route('admin.users.destroy', $agent)])
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                {{ __('app.no_results') }}
                                — <a href="{{ route('admin.dedicated.create') }}">{{ __('dedicated::admin.create_agent') }}</a>
                            </td>
                        </tr>
                    @endforelse
                </x-table>
            </div>
            @if ($agents->hasPages())
                <div class="card-footer">{{ $agents->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
