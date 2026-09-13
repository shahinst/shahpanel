@extends('layouts.panel')

@section('page_title', __('clients.payment_card_settings'))

@section('panel_content')
@php use Illuminate\Support\Facades\Route; @endphp
<x-alert type="warning">
    {{ __('clients.migration_required') }}
    @if ($panel === 'admin' && Route::has('admin.maintenance.index'))
        — <a href="{{ route('admin.maintenance.index') }}">{{ __('menu.maintenance') }}</a>
    @else
        — {{ __('tickets.migration_hint') }} (<code>maintain.php?do=migrate</code>)
    @endif
</x-alert>
@endsection
