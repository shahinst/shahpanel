@extends('layouts.panel')

@section('page_title', __('ui.tunneling_setup_page_title'))

@section('panel_content')
<div class="panel-modern-card mb-3 border-warning">
    <div class="card-head"><h3 class="text-warning mb-0">{{ __('ui.tunneling_setup_heading') }}</h3></div>
    <div class="card-body">
        <p>{{ __('ui.tunneling_setup_body') }}</p>

        @if ($error)
            <div class="alert alert-danger mb-3"><strong>{{ __('ui.label_error') }}:</strong> <code dir="ltr">{{ $error }}</code></div>
        @endif

        @if ($missingTables !== [])
            <div class="alert alert-warning mb-3">
                <strong>{{ __('ui.missing_items') }}:</strong>
                <code dir="ltr" class="d-block mt-2">{{ implode(', ', $missingTables) }}</code>
            </div>
        @endif

        <pre class="bg-light p-3 rounded small mb-0" dir="ltr">php artisan migrate --force
php artisan optimize:clear
php artisan tunneling:doctor</pre>
    </div>
</div>
@endsection
