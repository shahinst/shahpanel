@extends('layouts.panel')

@section('page_title', __('menu.agents_inbound'))

@section('panel_content')
    <x-page-header :title="__('menu.agents_inbound')">
        <p class="text-muted mb-0">{{ __('dedicated::admin.inbound_intro') }}</p>
        <x-slot:actions>
            <x-button :href="route('admin.inbound-agents.volume')" variant="outline-primary">
                <i class="bx bx-package"></i> {{ __('dedicated::admin.volume_and_requests') }}
                @if ($pendingCount > 0)
                    <span class="badge bg-danger ms-1">{{ persian_digits($pendingCount) }}</span>
                @endif
            </x-button>
            <x-button :href="route('admin.inbound-agents.create')"><i class="bx bx-user-plus"></i> {{ __('dedicated::admin.create_agent') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-body">
            <form method="GET" class="row g-2 mb-3">
                <div class="col-md-6">
                    <input type="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('app.search') }}">
                </div>
                <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bx bx-search"></i></button></div>
            </form>

            <x-table :headers="[
                __('dedicated::admin.username'),
                __('dedicated::admin.inbound'),
                __('dedicated::admin.volume_used'),
                __('dedicated::admin.balance'),
                __('dedicated::admin.status'),
                __('app.actions'),
            ]">
                @forelse ($agents as $agent)
                    @php
                        $rows = $allocations->get($agent->id, collect());
                        $wallet = $agent->wallets->first();
                        $waiting = (int) ($pendingByAgent[$agent->id] ?? 0);
                    @endphp
                    <tr>
                        <td>
                            <strong dir="ltr">{{ $agent->username }}</strong>
                            <div class="small text-muted">{{ $agent->full_name }}</div>
                        </td>
                        <td>
                            @foreach ($rows as $row)
                                <div class="small">
                                    {{ $row->label() }}
                                    <span class="text-muted">· {{ $row->server?->name }}</span>
                                    @unless ($row->isActive())
                                        <span class="badge bg-warning text-dark">{{ __('dedicated::admin.suspended') }}</span>
                                    @endunless
                                </div>
                            @endforeach
                        </td>
                        <td style="min-width: 11rem">
                            @foreach ($rows as $row)
                                @php $pct = $row->usedPercent(); @endphp
                                <div class="small mb-1" dir="ltr">
                                    {{ format_data_size($row->used_bytes) }} / {{ format_data_size($row->quota_bytes) }}
                                </div>
                                <div class="progress mb-2" style="height: 6px">
                                    <div class="progress-bar {{ $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success') }}" style="width: {{ min(100, $pct) }}%"></div>
                                </div>
                            @endforeach
                            @if ($waiting > 0)
                                <span class="badge bg-info">{{ __('dedicated::admin.requests_waiting', ['count' => persian_digits($waiting)]) }}</span>
                            @endif
                        </td>
                        <td dir="ltr">{{ $wallet ? format_money($wallet->balance, $wallet->currency) : format_money(0, \App\Enums\MoneyCurrency::IRT) }}</td>
                        <td>
                            <span class="badge {{ $agent->status->value === 'active' ? 'bg-success' : 'bg-secondary' }}">{{ __('dedicated::admin.'.($agent->status->value === 'active' ? 'active' : 'suspended')) }}</span>
                        </td>
                        <td>
                            <div class="icon-actions">
                                <x-icon-action :href="route('admin.inbound-agents.edit', $agent)" icon="bx-cog" variant="primary" :label="__('dedicated::admin.agent_settings')" />
                                <x-icon-action :href="route('admin.users.edit', $agent)" icon="bx-edit" variant="secondary" :label="__('dedicated::admin.edit_profile')" />
                                @can('impersonate', $agent)
                                    <x-icon-action :action="route('admin.users.impersonate', $agent)" icon="bx-log-in-circle" variant="info" :label="__('security.impersonation_as')" />
                                @endcan
                                @include('shared.users.delete-button', ['deleteUser' => $agent, 'deleteRoute' => route('admin.users.destroy', $agent)])
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">{{ __('dedicated::admin.no_inbound_agents') }}</td></tr>
                @endforelse
            </x-table>
        </div>
        @if ($agents->hasPages())
            <div class="card-footer">{{ $agents->links() }}</div>
        @endif
    </div>
@endsection
