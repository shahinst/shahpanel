@extends('layouts.panel')

@section('page_title', __('agents.create'))

@section('panel_content')
<x-card>
    <form method="POST" action="{{ route('admin.users.store') }}">
        @csrf
        <div class="row">
            @include('admin.users._form')
            <x-form.actions>
                <x-button type="submit">{{ __('app.save') }}</x-button>
                <x-button :href="route('admin.users.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</x-card>
@endsection
