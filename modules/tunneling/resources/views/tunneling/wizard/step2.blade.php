@extends('layouts.panel')

@section('page_title', __('tunneling.wizard_title'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('tunneling.wizard_title'),
    'subtitle' => __('tunneling.wizard_subtitle'),
    'icon' => 'bx-magic',
    'actions' => '<a href="'.route('admin.tunneling.index').'" class="btn btn-light">'.e(__('app.cancel')).'</a>',
])

@include('tunneling::wizard._stepper', ['current' => 2])

@php
    $selectedIran = old('iran_server_id', $data['iran_server_id'] ?? null);
    $selectedExits = old('exit_ids', $data['exit_ids'] ?? []);
@endphp

<form method="POST" action="{{ route('admin.tunneling.wizard.step2.store') }}">
    @csrf

    <div class="panel-modern-card mb-3">
        <div class="card-head"><h3><i class="bx bx-server align-middle"></i> {{ __('tunneling.wizard_step2_title') }}</h3></div>
        <div class="card-body">
            <p class="text-muted">{{ __('tunneling.wizard_step2_hint') }}</p>

            <x-form.group :label="__('tunneling.iran_server')">
                <select name="iran_server_id" id="wizard-iran-server" class="form-select" required>
                    <option value="">—</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}" @selected((int) $selectedIran === $server->id)>{{ $server->name }} ({{ $server->host }})</option>
                    @endforeach
                </select>
            </x-form.group>

            <x-form.group :label="__('tunneling.exit_servers')">
                <div id="wizard-exit-list">
                    @foreach ($servers as $server)
                        <div class="form-check wizard-exit-option" data-server-id="{{ $server->id }}">
                            <input class="form-check-input" type="checkbox" name="exit_ids[]" id="exit-{{ $server->id }}"
                                   value="{{ $server->id }}" @checked(in_array($server->id, (array) $selectedExits))>
                            <label class="form-check-label" for="exit-{{ $server->id }}">{{ $server->name }} ({{ $server->host }})</label>
                        </div>
                    @endforeach
                </div>
                <small class="text-muted d-block mt-1">{{ __('tunneling.select_exits_hint') }}</small>
            </x-form.group>
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="{{ route('admin.tunneling.wizard.step1') }}" class="btn btn-light"><i class="bx bx-chevron-right"></i> {{ __('tunneling.wizard_back') }}</a>
        <button class="btn btn-primary">{{ __('tunneling.wizard_next') }} <i class="bx bx-chevron-left"></i></button>
    </div>
</form>

<script>
(function () {
    var iranSelect = document.getElementById('wizard-iran-server');
    var exitOptions = document.querySelectorAll('.wizard-exit-option');

    function syncExitOptions() {
        var iranId = iranSelect.value;

        exitOptions.forEach(function (row) {
            var isIran = row.dataset.serverId === iranId && iranId !== '';
            var checkbox = row.querySelector('input[type="checkbox"]');
            row.style.display = isIran ? 'none' : '';

            if (isIran) {
                checkbox.checked = false;
            }
        });
    }

    iranSelect.addEventListener('change', syncExitOptions);
    syncExitOptions();
})();
</script>
@endsection
