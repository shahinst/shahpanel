@extends('layouts.panel')

@section('page_title', __('packages.create'))

@section('panel_content')
<x-card>
    <form method="POST" action="{{ route('admin.packages.store') }}">
        @csrf
        <div class="row">
            @include('admin.packages._form', ['servers' => $servers])
            <x-form.actions>
                <x-button type="submit">{{ __('app.save') }}</x-button>
                <x-button :href="route('admin.packages.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</x-card>
@endsection
