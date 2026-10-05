@extends('layouts.panel')

@section('page_title', __('dedicated::admin.agent_menu'))

@php
    $peak = max(1, (int) $chart->max());
    $healthy = $rows->filter(fn ($r) => $r->last_error === null && $r->last_read_at !== null)->count();
@endphp

@push('styles')
<style>
    .ded { display: grid; gap: 1rem; }
    .ded-hero { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem;
        padding: 1.25rem 1.4rem; border-radius: 18px; color: #fff;
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 55%, #0ea5e9 100%); box-shadow: 0 14px 34px -18px rgba(79,70,229,.7); }
    .ded-hero h1 { font-size: 1.25rem; font-weight: 800; margin: 0 0 .25rem; color: #fff; }
    .ded-hero p { margin: 0; opacity: .88; font-size: .88rem; }
    .ded-hero .btn { background: #fff; color: #4338ca; border: 0; font-weight: 700; border-radius: 12px; padding: .6rem 1rem; }
    .ded-pill { display: inline-flex; align-items: center; gap: .35rem; margin-top: .6rem; padding: .25rem .65rem;
        border-radius: 999px; background: rgba(255,255,255,.18); font-size: .78rem; }
    .ded-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
    @media (max-width: 991px) { .ded-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .ded-stat { background: var(--bs-body-bg, #fff); border: 1px solid rgba(15,23,42,.07); border-radius: 16px; padding: 1rem 1.1rem;
        display: flex; gap: .85rem; align-items: center; box-shadow: 0 4px 16px -12px rgba(15,23,42,.35); }
    .ded-stat__icon { width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center; font-size: 1.35rem; flex: none; }
    .ded-stat__label { font-size: .78rem; color: #64748b; }
    .ded-stat__value { font-size: 1.15rem; font-weight: 800; line-height: 1.3; }
    .ded-card { background: var(--bs-body-bg, #fff); border: 1px solid rgba(15,23,42,.07); border-radius: 16px; box-shadow: 0 4px 16px -12px rgba(15,23,42,.35); }
    .ded-card__head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .9rem 1.1rem; border-bottom: 1px solid rgba(15,23,42,.06); }
    .ded-card__head h2 { font-size: .98rem; font-weight: 700; margin: 0; display: flex; gap: .45rem; align-items: center; }
    .ded-card__body { padding: 1rem 1.1rem; }
    .ded-chart { display: flex; align-items: flex-end; gap: 6px; height: 170px; }
    .ded-chart__col { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; gap: 6px; min-width: 0; }
    .ded-chart__bar { width: 100%; max-width: 34px; border-radius: 8px 8px 3px 3px; background: linear-gradient(180deg, #818cf8, #4f46e5); min-height: 3px; }
    .ded-chart__bar.is-zero { background: #e2e8f0; }
    .ded-chart__day { font-size: .68rem; color: #94a3b8; white-space: nowrap; }
    .ded-servers { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: .75rem; }
    .ded-server { border: 1px solid rgba(15,23,42,.08); border-radius: 14px; padding: .9rem 1rem; display: grid; gap: .55rem; }
    .ded-server__top { display: flex; justify-content: space-between; align-items: center; gap: .5rem; }
    .ded-server__name { font-weight: 700; display: flex; align-items: center; gap: .4rem; min-width: 0; }
    .ded-server__name span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ded-server__grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: .4rem; }
    .ded-server__grid div { background: #f8fafc; border-radius: 10px; padding: .45rem .5rem; text-align: center; }
    .ded-server__grid small { display: block; font-size: .68rem; color: #64748b; }
    .ded-server__grid b { font-size: .86rem; }
    .ded-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; flex: none; }
    .ded-pkgs { display: grid; gap: .6rem; }
    .ded-pkg { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem;
        border: 1px solid rgba(15,23,42,.08); border-radius: 14px; padding: .8rem 1rem; }
    .ded-pkg__name { font-weight: 700; }
    .ded-pkg__meta { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .3rem; }
    .ded-tag { font-size: .72rem; padding: .15rem .55rem; border-radius: 999px; background: #eef2ff; color: #4338ca; }
    .ded-tag--muted { background: #f1f5f9; color: #475569; }
    .ded-pkg__actions { display: flex; gap: .4rem; }
    .ded-empty { text-align: center; color: #94a3b8; padding: 1.5rem .5rem; }
    .ded-empty i { font-size: 2rem; display: block; margin-bottom: .35rem; }
</style>
@endpush

@section('panel_content')
<div class="ded">
    <div class="ded-hero">
        <div>
            <h1><i class="bx bx-server"></i> {{ __('dedicated::admin.agent_menu') }}</h1>
            <p>{{ __('dedicated::admin.agent_intro') }}</p>
            <span class="ded-pill"><i class="bx bx-pulse"></i> {{ __('dedicated::admin.dash_servers_ok', ['ok' => persian_digits($healthy), 'all' => persian_digits($rows->count())]) }}</span>
        </div>
        <a href="{{ route('agent.dedicated.packages.create') }}" class="btn"><i class="bx bx-plus"></i> {{ __('dedicated::admin.new_package') }}</a>
    </div>

    <div class="ded-stats">
        <div class="ded-stat">
            <div class="ded-stat__icon" style="background:#eef2ff;color:#4f46e5"><i class="bx bx-download"></i></div>
            <div><div class="ded-stat__label">{{ __('dedicated::admin.usage_total') }}</div><div class="ded-stat__value">{{ format_data_size($total) }}</div></div>
        </div>
        <div class="ded-stat">
            <div class="ded-stat__icon" style="background:#e0f2fe;color:#0284c7"><i class="bx bx-calendar"></i></div>
            <div><div class="ded-stat__label">{{ __('dedicated::admin.usage_month') }}</div><div class="ded-stat__value">{{ format_data_size($month) }}</div></div>
        </div>
        <div class="ded-stat">
            <div class="ded-stat__icon" style="background:#dcfce7;color:#16a34a"><i class="bx bx-time-five"></i></div>
            <div><div class="ded-stat__label">{{ __('dedicated::admin.usage_today') }}</div><div class="ded-stat__value">{{ format_data_size($today) }}</div></div>
        </div>
        <div class="ded-stat">
            <div class="ded-stat__icon" style="background:#fef3c7;color:#d97706"><i class="bx bx-group"></i></div>
            <div><div class="ded-stat__label">{{ __('dedicated::admin.dash_accounts') }}</div>
                <div class="ded-stat__value">{{ persian_digits($accountsActive) }} <small class="text-muted fw-normal">/ {{ persian_digits($accountsTotal) }}</small></div></div>
        </div>
    </div>

    <div class="ded-card">
        <div class="ded-card__head"><h2><i class="bx bx-bar-chart-alt-2"></i> {{ __('dedicated::admin.usage_chart') }}</h2></div>
        <div class="ded-card__body">
            @if ($chart->sum() === 0)
                <div class="ded-empty"><i class="bx bx-line-chart"></i>{{ __('dedicated::admin.chart_empty') }}</div>
            @else
                <div class="ded-chart">
                    @foreach ($chart as $day => $rx)
                        <div class="ded-chart__col" title="{{ format_data_size($rx) }}">
                            <div @class(['ded-chart__bar', 'is-zero' => $rx === 0]) style="height: {{ max(2, round($rx / $peak * 100)) }}%"></div>
                            <span class="ded-chart__day">{{ persian_digits(\Illuminate\Support\Carbon::parse($day)->format('m/d')) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="ded-card">
        <div class="ded-card__head"><h2><i class="bx bx-server"></i> {{ __('dedicated::admin.dash_servers') }}</h2></div>
        <div class="ded-card__body">
            <div class="ded-servers">
                @forelse ($rows as $row)
                    @php
                        $state = $row->meter_interface === null ? 'off' : ($row->last_error !== null ? 'error' : ($row->last_read_at ? 'ok' : 'wait'));
                        $color = ['ok' => '#22c55e', 'error' => '#ef4444', 'wait' => '#f59e0b', 'off' => '#94a3b8'][$state];
                    @endphp
                    <div class="ded-server">
                        <div class="ded-server__top">
                            <div class="ded-server__name"><span class="ded-dot" style="background:{{ $color }}"></span><span>{{ $row->server?->name }}</span></div>
                            <span class="ded-tag ded-tag--muted">{{ $row->server?->type?->label() }}</span>
                        </div>
                        <div class="ded-server__grid">
                            <div><small>{{ __('dedicated::admin.usage_total') }}</small><b>{{ format_data_size($row->total_rx_bytes) }}</b></div>
                            <div><small>{{ __('dedicated::admin.usage_today') }}</small><b>{{ format_data_size((int) ($todayPer[$row->server_id] ?? 0)) }}</b></div>
                            <div><small>{{ __('dedicated::admin.dash_accounts') }}</small><b>{{ persian_digits((int) ($perServer[$row->server_id] ?? 0)) }}</b></div>
                        </div>
                        <small class="text-muted">
                            @if ($state === 'off')
                                {{ __('dedicated::admin.meter_off') }}
                            @elseif ($state === 'error')
                                <span class="text-danger">{{ $row->last_error }}</span>
                            @else
                                {{ __('dedicated::admin.last_read') }}: {{ $row->last_read_at ? $row->last_read_at->diffForHumans() : '—' }}
                                · <span dir="ltr">{{ $row->meter_interface }}</span>
                            @endif
                        </small>
                    </div>
                @empty
                    <div class="ded-empty"><i class="bx bx-server"></i>{{ __('dedicated::admin.no_servers') }}</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="ded-card">
        <div class="ded-card__head">
            <h2><i class="bx bx-package"></i> {{ __('dedicated::admin.my_packages') }}</h2>
            <a href="{{ route('agent.dedicated.packages.create') }}" class="btn btn-sm btn-primary"><i class="bx bx-plus"></i> {{ __('dedicated::admin.new_package') }}</a>
        </div>
        <div class="ded-card__body">
            <div class="ded-pkgs">
                @forelse ($packages as $package)
                    @php $tiers = $package->durations->where('is_enabled', true); @endphp
                    <div class="ded-pkg">
                        <div>
                            <div class="ded-pkg__name">
                                <span class="ded-dot" style="background:{{ $package->is_active ? '#22c55e' : '#94a3b8' }}"></span>
                                {{ $package->name }}
                            </div>
                            <div class="ded-pkg__meta">
                                <span class="ded-tag">{{ $package->service_type?->label() }}</span>
                                <span class="ded-tag ded-tag--muted">{{ $package->data_limit_gb ? persian_digits((float) $package->data_limit_gb).' GB' : __('dedicated::admin.unlimited_short') }}</span>
                                @foreach ($tiers as $tier)
                                    <span class="ded-tag ded-tag--muted">{{ $tier->tier->label() }} · {{ format_money($tier->price) }}</span>
                                @endforeach
                            </div>
                        </div>
                        <div class="ded-pkg__actions">
                            <a href="{{ route('agent.dedicated.packages.edit', $package) }}" class="btn btn-sm btn-light" title="{{ __('dedicated::admin.edit_package') }}"><i class="bx bx-edit"></i></a>
                            <form method="POST" action="{{ route('agent.dedicated.packages.destroy', $package) }}" data-confirm="{{ __('dedicated::admin.delete_confirm') }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-light text-danger" title="{{ __('dedicated::admin.delete') }}"><i class="bx bx-trash"></i></button>
                            </form>
                        </div>
                    </div>
                @empty
                    <div class="ded-empty"><i class="bx bx-package"></i>{{ __('dedicated::admin.no_packages') }}</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
