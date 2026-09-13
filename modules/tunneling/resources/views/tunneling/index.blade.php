@extends('layouts.panel')

@section('page_title', __('tunneling.title'))

@section('panel_content')
@include('tunneling::_queue-status')

@php
    $removingGroups = $groups->filter(fn ($g) => $g->status === \App\Enums\TunnelGroupStatus::Removing);
@endphp
@if ($removingGroups->isNotEmpty())
    <x-alert type="info" class="mb-3">
        {{ __('tunneling.teardown_running_banner', ['started' => now()->format('H:i')]) }}
    </x-alert>
    <meta http-equiv="refresh" content="5">
@endif

@include('partials.panel-page-hero', [
    'title' => __('tunneling.title'),
    'subtitle' => __('tunneling.subtitle'),
    'icon' => 'bx-git-branch',
    'actions' => '<a href="'.route('admin.tunneling.wizard.step1').'" class="btn btn-primary"><i class="bx bx-magic"></i> '.e(__('tunneling.wizard_create_button')).'</a>'
        .' <a href="'.route('admin.tunneling.groups.create').'" class="btn btn-light"><i class="bx bx-plus"></i> '.e(__('tunneling.create_group_advanced')).'</a>'
        .' <a href="'.route('admin.tunneling.locations.index').'" class="btn btn-light"><i class="bx bx-map"></i> '.e(__('tunneling.locations')).'</a>',
])

{{-- Fleet overview --}}
<div class="panel-modern-card mb-3">
    <div class="card-head">
        <h3><i class="bx bx-server align-middle"></i> {{ __('tunneling.fleet') }}</h3>
    </div>
    <div class="card-body">
        <div class="row g-2">
            @foreach ($servers as $server)
                @php
                    $cpu = $server->last_cpu_pct;
                    $ctRatio = ($server->last_conntrack && $server->last_conntrack_max)
                        ? $server->last_conntrack / max(1, $server->last_conntrack_max)
                        : null;
                    $tone = 'success';
                    if (($cpu !== null && $cpu >= 70) || ($ctRatio !== null && $ctRatio >= 0.8)) {
                        $tone = 'danger';
                    } elseif (($cpu !== null && $cpu >= 50) || ($ctRatio !== null && $ctRatio >= 0.6)) {
                        $tone = 'warning';
                    } elseif ($server->metricsSampledAt() === null) {
                        $tone = 'secondary';
                    }
                @endphp
                <div class="col-6 col-md-4 col-xl-3">
                    <div class="border rounded p-2 h-100 border-{{ $tone }}">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong>{{ $server->name }}</strong>
                            <span class="badge bg-{{ $tone }}">&nbsp;</span>
                        </div>
                        <small class="text-muted d-block">
                            CPU: {{ $cpu !== null ? persian_digits($cpu).'%' : '—' }}
                            | conntrack: {{ $server->last_conntrack !== null ? persian_digits(number_format($server->last_conntrack)) : '—' }}
                        </small>
                        <small class="text-muted d-block">
                            {{ $server->last_throughput_bps !== null ? persian_digits(round($server->last_throughput_bps / 1000000, 1)).' Mbps' : '—' }}
                            @if ($sampledAt = $server->metricsSampledAt())
                                | {{ $sampledAt->diffForHumans() }}
                            @endif
                        </small>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Groups list --}}
<div class="panel-modern-card">
    <div class="card-head">
        <h3>{{ __('tunneling.groups') }}</h3>
    </div>
    <div class="card-body">
        <x-table :headers="[__('tunneling.name'), __('tunneling.kind'), __('tunneling.iran_server'), __('tunneling.exit_servers'), __('tunneling.agents'), 'MTU', __('tunneling.status'), __('app.actions')]">
            @forelse ($groups as $group)
                @php
                    $agents = $group->exits->flatMap->agents;
                    $upCount = $agents->where('health', \App\Enums\AgentHealth::Up)->count();
                @endphp
                <tr>
                    <td><strong>{{ $group->name }}</strong>
                        @if ($group->isReverse())
                            <span class="badge bg-info">{{ __('tunneling.direction_reverse') }}</span>
                        @endif
                    </td>
                    <td>{{ $group->kind->label() }}</td>
                    <td>{{ $group->iranServer?->name ?? '—' }}</td>
                    <td>{{ $group->exits->map(fn ($e) => $e->server?->name)->filter()->implode('، ') }}</td>
                    <td>{{ persian_digits($upCount) }}/{{ persian_digits($agents->count()) }}</td>
                    <td>{{ $group->effectiveMtu() !== null ? persian_digits($group->effectiveMtu()) : '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $group->status->cssClass() }}">{{ $group->status->label() }}</span>
                    </td>
                    <td class="text-nowrap">
                        <a href="{{ route('admin.tunneling.groups.show', $group) }}" class="btn btn-sm btn-primary">
                            <i class="bx bx-cog"></i> {{ __('servers.manage') }}
                        </a>
                        @if ($group->status === \App\Enums\TunnelGroupStatus::Removing)
                            <form method="POST" action="{{ route('admin.tunneling.groups.delete', $group) }}" class="d-inline"
                                  onsubmit="return confirm(@js(__('tunneling.teardown_confirm')))">
                                @csrf
                                <button class="btn btn-sm btn-danger"><i class="bx bx-trash"></i> {{ __('tunneling.teardown_retry') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        {{ __('tunneling.no_groups') }}
                        — <a href="{{ route('admin.tunneling.groups.create') }}">{{ __('tunneling.create_group') }}</a>
                    </td>
                </tr>
            @endforelse
        </x-table>
    </div>
    @if ($groups->hasPages())
        <div class="card-foot">{{ $groups->links() }}</div>
    @endif
</div>
@endsection
