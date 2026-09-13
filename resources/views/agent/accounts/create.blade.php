@extends('layouts.panel')

@section('page_title', __('menu.accounts'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('accounts.create'),
    'subtitle' => 'صدور اکانت جدید',
    'icon' => 'bx-plus-circle',
])

<div class="panel-form-section">
    <form method="POST" action="{{ route('agent.accounts.store') }}">
        @csrf
        <div class="row">
            @include('agent.accounts._form', compact('accountOwners', 'packages', 'servers', 'packageOptions', 'autoServer'))
            <x-form.actions>
                <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
                <x-button :href="route('agent.accounts.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</div>
@endsection
