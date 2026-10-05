@extends('layouts.panel')

@section('page_title', $package ? __('dedicated::admin.edit_package') : __('dedicated::admin.new_package'))

@section('panel_content')
{{-- The admin's own package form, unchanged; the controller hands it only this agent's servers and inbounds. --}}
<x-card>
    <form method="POST" action="{{ $package ? route('agent.dedicated.packages.update', $package) : route('agent.dedicated.packages.store') }}">
        @csrf
        @if ($package)
            @method('PUT')
        @endif
        <div class="row">
            @include('admin.packages._form', ['servers' => $servers, 'package' => $package])
            <x-form.actions>
                <x-button type="submit">{{ __('app.save') }}</x-button>
                <x-button :href="url()->previous()" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</x-card>
@endsection
