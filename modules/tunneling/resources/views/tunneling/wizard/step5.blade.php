@extends('layouts.panel')

@section('page_title', __('tunneling.wizard_title'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('tunneling.wizard_title'),
    'subtitle' => __('tunneling.wizard_subtitle'),
    'icon' => 'bx-magic',
    'actions' => '<a href="'.route('admin.tunneling.index').'" class="btn btn-light">'.e(__('app.cancel')).'</a>',
])

@include('tunneling::wizard._stepper', ['current' => 5])

<div class="panel-modern-card mb-3">
    <div class="card-head"><h3><i class="bx bx-list-check align-middle"></i> {{ __('tunneling.wizard_step5_title') }}</h3></div>
    <div class="card-body">
        <table class="table table-borderless mb-0">
            <tbody>
                <tr>
                    <th class="text-muted" style="width: 220px;">{{ __('tunneling.name') }}</th>
                    <td>{{ $data['name'] ?? __('tunneling.wizard_name_auto') }}</td>
                </tr>
                <tr>
                    <th class="text-muted">{{ __('tunneling.wizard_client_service_types') }}</th>
                    <td>
                        @foreach (($data['client_service_types'] ?? []) as $type)
                            <span class="badge bg-secondary">{{ $type === 'wireguard' ? 'WireGuard' : 'PPP' }}</span>
                        @endforeach
                    </td>
                </tr>
                <tr>
                    <th class="text-muted">{{ __('tunneling.iran_server') }}</th>
                    <td>{{ $iranServer?->name ?? '—' }} ({{ $iranServer?->host }})</td>
                </tr>
                <tr>
                    <th class="text-muted">{{ __('tunneling.exit_servers') }}</th>
                    <td>{{ $exitServers->map(fn ($s) => $s->name)->implode('، ') }}</td>
                </tr>
                <tr>
                    <th class="text-muted">{{ __('tunneling.kind') }}</th>
                    <td>
                        @foreach ($kinds as $kind)
                            <span class="badge bg-info">{{ $kind->label() }}</span>
                        @endforeach
                    </td>
                </tr>
                @if (count($kinds) > 1)
                    <tr>
                        <th class="text-muted">{{ __('tunneling.wizard_multi_kind_title') }}</th>
                        <td>
                            @if ($priorityOrder !== null)
                                {{ __('tunneling.wizard_balance_priority') }} —
                                {{ collect($priorityOrder)->map(fn ($k) => $k->label())->implode(' ← ') }}
                            @else
                                {{ __('tunneling.wizard_balance_balanced') }}
                            @endif
                        </td>
                    </tr>
                @endif
                <tr>
                    <th class="text-muted">{{ __('tunneling.balancing_mode') }}</th>
                    <td>{{ count($exitServers) > 1 ? __('tunneling.balancing_range_split') : __('tunneling.balancing_pcc') }}</td>
                </tr>
                <tr>
                    <th class="text-muted">{{ __('tunneling.wizard_step4_title') }}</th>
                    <td>{{ ! empty($data['wipe_previous']) ? __('tunneling.wizard_wipe_yes') : __('tunneling.wizard_wipe_no') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<x-alert type="warning" class="mb-3">
    {{ __('tunneling.wizard_finish_hint') }}
</x-alert>

<div class="d-flex gap-2">
    <form method="POST" action="{{ route('admin.tunneling.wizard.finish') }}" id="wizard-finish-form" class="d-flex gap-2">
        @csrf
        <a href="{{ route('admin.tunneling.wizard.step4') }}" class="btn btn-light"><i class="bx bx-chevron-right"></i> {{ __('tunneling.wizard_back') }}</a>
        <button class="btn btn-success" id="wizard-finish-btn">
            <i class="bx bx-play-circle"></i> {{ __('tunneling.wizard_start') }}
        </button>
    </form>
    <form method="POST" action="{{ route('admin.tunneling.wizard.restart') }}" class="ms-auto">
        @csrf
        <button class="btn btn-outline-danger btn-sm">{{ __('tunneling.wizard_restart') }}</button>
    </form>
</div>

<script>
document.getElementById('wizard-finish-form').addEventListener('submit', function () {
    var btn = document.getElementById('wizard-finish-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> {{ __('tunneling.wizard_starting') }}';
});
</script>
@endsection
