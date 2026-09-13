@extends('layouts.panel')

@section('page_title', __('menu.accounts'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('app.edit').': '.$account->remote_username,
    'subtitle' => $account->service_type->label(),
    'icon' => 'bx-edit',
])

<div class="panel-form-section">
    <form method="POST" action="{{ route('seller.accounts.update', $account) }}">
        @csrf
        @method('PUT')
        <div class="row">
            @include('agent.accounts._form', ['account' => $account])
            <x-form.actions>
                <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
                <x-button :href="route('seller.accounts.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</div>
@endsection
