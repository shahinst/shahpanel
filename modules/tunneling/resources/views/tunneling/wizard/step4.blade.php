@extends('layouts.panel')

@section('page_title', __('tunneling.wizard_title'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('tunneling.wizard_title'),
    'subtitle' => __('tunneling.wizard_subtitle'),
    'icon' => 'bx-magic',
    'actions' => '<a href="'.route('admin.tunneling.index').'" class="btn btn-light">'.e(__('app.cancel')).'</a>',
])

@include('tunneling::wizard._stepper', ['current' => 4])

@php
    $wipePrevious = old('wipe_previous', $data['wipe_previous'] ?? false);
@endphp

<form method="POST" action="{{ route('admin.tunneling.wizard.step4.store') }}">
    @csrf

    <div class="panel-modern-card mb-3">
        <div class="card-head"><h3><i class="bx bx-eraser align-middle"></i> {{ __('tunneling.wizard_step4_title') }}</h3></div>
        <div class="card-body">
            <p class="text-muted">{{ __('tunneling.wizard_step4_hint') }}</p>

            <div class="form-check mb-1">
                <input class="form-check-input" type="radio" name="wipe_previous" id="wipe-no" value="0" @checked(! $wipePrevious)>
                <label class="form-check-label" for="wipe-no">{{ __('tunneling.wizard_wipe_no') }}</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="wipe_previous" id="wipe-yes" value="1" @checked((bool) $wipePrevious)>
                <label class="form-check-label" for="wipe-yes">{{ __('tunneling.wizard_wipe_yes') }}</label>
                <small class="text-muted d-block">{{ __('tunneling.wizard_wipe_yes_hint') }}</small>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="{{ route('admin.tunneling.wizard.step3') }}" class="btn btn-light"><i class="bx bx-chevron-right"></i> {{ __('tunneling.wizard_back') }}</a>
        <button class="btn btn-primary">{{ __('tunneling.wizard_next') }} <i class="bx bx-chevron-left"></i></button>
    </div>
</form>
@endsection
