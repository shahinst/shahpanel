@extends('layouts.panel')

@section('page_title', __('admins.create'))

@section('panel_content')
<x-card>
    <form method="POST" action="{{ route('admin.administrators.store') }}">
        @csrf
        <div class="row">
            @include('admin.administrators._form')
            <x-form.actions>
                <x-button type="submit">{{ __('app.save') }}</x-button>
                <x-button :href="route('admin.administrators.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</x-card>
@endsection
