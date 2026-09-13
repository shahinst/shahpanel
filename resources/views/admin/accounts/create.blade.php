@extends('layouts.panel')

@section('page_title', __('accounts.create'))

@section('panel_content')
<x-card>
    <form method="POST" action="{{ route('admin.accounts.store') }}">
        @csrf
        <div class="row">
            @include('admin.accounts._create_form', compact('accountOwners', 'packages', 'servers', 'packageOptions', 'autoServer'))
            <x-form.actions>
                <x-button type="submit" id="admin-create-submit">{{ __('app.save') }}</x-button>
                <x-button :href="route('admin.accounts.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</x-card>
@endsection
