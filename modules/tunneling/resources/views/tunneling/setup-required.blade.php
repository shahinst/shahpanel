@extends('layouts.panel')

@section('page_title', __('tunneling.title'))

@section('panel_content')
<div class="panel-modern-card mb-3 border-warning">
    <div class="card-head"><h3 class="text-warning mb-0"><i class="bx bx-error"></i> {{ __('tunneling.setup_required') }}</h3></div>
    <div class="card-body">
        <p>{{ __('tunneling.setup_required_hint') }}</p>

        @if ($error)
            <x-alert type="error" class="mb-3"><strong>{{ __('app.error') }}:</strong> <code dir="ltr">{{ $error }}</code></x-alert>
        @endif

        @if ($missingTables !== [])
            <x-alert type="warning" class="mb-3">
                <strong>{{ __('tunneling.missing_tables') }}:</strong>
                <code dir="ltr" class="d-block mt-2">{{ implode(', ', $missingTables) }}</code>
            </x-alert>
        @endif

        <ol class="mb-3">
            <li>{{ __('tunneling.setup_step_migrate') }}</li>
            <li>{{ __('tunneling.setup_step_clear') }}</li>
            <li>{{ __('tunneling.setup_step_doctor') }}</li>
        </ol>

        <pre class="bg-light p-3 rounded small mb-3" dir="ltr">php artisan migrate --force
php artisan optimize:clear
php artisan tunneling:doctor</pre>

        @if (Route::has('admin.maintenance.index'))
            <a href="{{ route('admin.maintenance.index') }}" class="btn btn-primary btn-sm">
                <i class="bx bx-wrench"></i> {{ __('menu.maintenance') }}
            </a>
        @endif
    </div>
</div>
@endsection
