@extends('layouts.panel')

@section('page_title', __('dedicated::admin.agent_menu'))

@section('panel_content')
<x-page-header :title="__('dedicated::admin.agent_menu')" :subtitle="__('dedicated::admin.agent_intro')">
    <a href="{{ route('agent.dedicated.packages.create') }}" class="btn btn-primary"><i class="bx bx-plus"></i> {{ __('dedicated::admin.new_package') }}</a>
</x-page-header>

<div class="row g-3 mb-3">
    <div class="col-md-4"><x-stat-card :title="__('dedicated::admin.usage_total')" :value="format_data_size($total)" icon="bx-download" /></div>
    <div class="col-md-4"><x-stat-card :title="__('dedicated::admin.usage_month')" :value="format_data_size($month)" icon="bx-calendar" /></div>
    <div class="col-md-4"><x-stat-card :title="__('dedicated::admin.usage_today')" :value="format_data_size($today)" icon="bx-time" /></div>
</div>

@if ($chart->isNotEmpty())
    @php $max = max(1, (int) $chart->max()); @endphp
    <div class="panel-modern-card mb-3"><div class="card-body">
        <h2 class="h6">{{ __('dedicated::admin.usage_chart') }}</h2>
        <div class="d-flex align-items-end gap-1" style="height:120px">
            @foreach ($chart as $day => $rx)
                <div class="flex-fill bg-primary rounded-top" style="height:{{ max(2, (int) round($rx / $max * 100)) }}%" title="{{ $day }} · {{ format_data_size((int) $rx) }}"></div>
            @endforeach
        </div>
    </div></div>
@endif

<div class="panel-modern-card mb-3">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>{{ __('dedicated::admin.server') }}</th><th>{{ __('dedicated::admin.status') }}</th><th>{{ __('dedicated::admin.usage_total') }}</th><th>{{ __('dedicated::admin.last_read') }}</th></tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row->server?->name }} <span class="text-muted small">· {{ $row->server?->type?->value }}</span></td>
                        <td>{!! $row->server?->is_active ? '<span class="badge bg-success">'.e(__('app.active')).'</span>' : '<span class="badge bg-secondary">'.e(__('app.inactive')).'</span>' !!}</td>
                        <td dir="ltr">{{ $row->meter_interface ? format_data_size($row->total_rx_bytes) : '—' }}</td>
                        <td>{{ $row->last_read_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="panel-modern-card">
    <div class="card-body"><h2 class="h6 mb-0">{{ __('dedicated::admin.my_packages') }}</h2></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>{{ __('dedicated::admin.package') }}</th><th>{{ __('dedicated::admin.service_type') }}</th><th>{{ __('dedicated::admin.prices') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($packages as $package)
                    <tr>
                        <td>{{ $package->name }} @unless ($package->is_active)<span class="badge bg-secondary">{{ __('app.inactive') }}</span>@endunless</td>
                        <td>{{ $package->service_type?->label() }}</td>
                        <td class="small">
                            @foreach ($package->durations->where('is_enabled', true) as $d){{ $d->tier->label() }}: {{ format_money($d->price) }}@if (! $loop->last) · @endif @endforeach
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('agent.dedicated.packages.edit', $package) }}" class="btn btn-sm btn-light"><i class="bx bx-edit"></i></a>
                            <form method="POST" action="{{ route('agent.dedicated.packages.destroy', $package) }}" class="d-inline" data-confirm="{{ __('dedicated::admin.delete_confirm') }}">
                                @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bx bx-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">{{ __('dedicated::admin.no_packages') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
