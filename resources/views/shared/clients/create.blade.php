@extends('layouts.panel')

@section('page_title', __('clients.create_client'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('clients.create_client'),
    'subtitle' => __('clients.create_standalone_hint'),
    'icon' => 'bx-user-plus',
    'actions' => '<a href="'.route($panel.'.clients.index').'" class="btn btn-outline-light btn-sm"><i class="bx bx-arrow-back"></i> '.e(__('app.back')).'</a>',
])

<div class="panel-form-section">
    <form method="POST" action="{{ route($panel.'.clients.store') }}">
        @csrf
        <div class="row">
            @if ($showOwnerSelect)
                <div class="col-md-6 mb-3">
                    <label class="form-label">{{ __('clients.owner') }}</label>
                    <select name="owner_id" class="form-select" required>
                        @foreach ($clientOwners as $owner)
                            <option value="{{ $owner->id }}" @selected(old('owner_id') == $owner->id)>
                                {{ $owner->full_name }} ({{ $owner->username }}) — {{ $owner->role->label() }}
                            </option>
                        @endforeach
                    </select>
                    <small class="text-muted">{{ __('clients.owner_select_hint') }}</small>
                </div>
            @endif

            <div class="col-md-6 mb-3">
                <label class="form-label">{{ __('auth.username') }}</label>
                <input name="username" value="{{ old('username') }}" class="form-control" required autocomplete="off">
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">{{ __('auth.password') }}</label>
                <input type="password" name="password" class="form-control" required autocomplete="new-password">
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">{{ __('validation.attributes.full_name') }}</label>
                <input name="full_name" value="{{ old('full_name') }}" class="form-control">
            </div>

            <div class="col-12">
                <x-form.actions>
                    <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
                    <x-button :href="route($panel.'.clients.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
                </x-form.actions>
            </div>
        </div>
    </form>
</div>
@endsection
