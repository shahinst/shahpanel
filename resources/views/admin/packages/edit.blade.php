@extends('layouts.panel')

@section('page_title', __('menu.packages'))

@section('panel_content')
<x-card>
    <form method="POST" action="{{ route('admin.packages.update', $package) }}">
        @csrf
        @method('PUT')
        <div class="row">
            @include('admin.packages._form', ['package' => $package, 'servers' => $servers, 'durationsByTier' => $durationsByTier])
            <x-form.actions>
                <x-button type="submit">{{ __('app.save') }}</x-button>
                <x-button :href="route('admin.packages.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</x-card>
@endsection
