@extends('layouts.panel')

@section('page_title', __('tickets.page_title'))

@section('panel_content')
<x-alert type="warning">
    {{ __('clients.migration_required') }}
    @if ($panel === 'admin' && \Illuminate\Support\Facades\Route::has('admin.maintenance.index'))
        — <a href="{{ route('admin.maintenance.index') }}">{{ __('menu.maintenance') }}</a>
    @else
        — {{ __('tickets.migration_hint') }} (<code>maintain.php?do=migrate</code>)
    @endif
</x-alert>
@endsection
