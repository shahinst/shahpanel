@extends('layouts.panel')

@section('page_title', __('tunneling.wizard_title'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('tunneling.wizard_title'),
    'subtitle' => __('tunneling.wizard_subtitle'),
    'icon' => 'bx-magic',
    'actions' => '<a href="'.route('admin.tunneling.index').'" class="btn btn-light">'.e(__('app.cancel')).'</a>',
])

@include('tunneling::wizard._stepper', ['current' => 1])

<form method="POST" action="{{ route('admin.tunneling.wizard.step1.store') }}">
    @csrf

    <div class="panel-modern-card mb-3">
        <div class="card-head"><h3><i class="bx bx-server align-middle"></i> {{ __('tunneling.wizard_step1_title') }}</h3></div>
        <div class="card-body">
            <p class="text-muted">{{ __('tunneling.wizard_step1_hint') }}</p>

            <x-form.group :label="__('tunneling.name')">
                <input name="name" value="{{ old('name', $data['name'] ?? '') }}" class="form-control" placeholder="{{ __('tunneling.wizard_name_placeholder') }}">
                <small class="text-muted">{{ __('tunneling.wizard_name_hint') }}</small>
            </x-form.group>

            <x-form.group :label="__('tunneling.wizard_client_service_types')">
                @php $selectedTypes = old('client_service_types', $data['client_service_types'] ?? ['wireguard']); @endphp
                <div class="row g-2">
                    <div class="col-sm-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="client_service_types[]" id="cst-wireguard"
                                   value="wireguard" @checked(in_array('wireguard', (array) $selectedTypes, true))>
                            <label class="form-check-label" for="cst-wireguard">WireGuard</label>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="client_service_types[]" id="cst-ppp"
                                   value="ppp" @checked(in_array('ppp', (array) $selectedTypes, true))>
                            <label class="form-check-label" for="cst-ppp">PPP (L2TP/PPTP)</label>
                        </div>
                    </div>
                </div>
                <small class="text-muted d-block mt-1">{{ __('tunneling.wizard_client_service_types_hint') }}</small>
            </x-form.group>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary">{{ __('tunneling.wizard_next') }} <i class="bx bx-chevron-left"></i></button>
    </div>
</form>
@endsection
