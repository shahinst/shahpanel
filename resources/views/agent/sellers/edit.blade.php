@extends('layouts.panel')

@section('page_title', __('app.edit').' — '.$seller->full_name)

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => $seller->full_name,
    'subtitle' => $seller->username,
    'icon' => 'bx-edit',
    'actions' => view('agent.sellers.partials.impersonate-button', ['seller' => $seller, 'hero' => true])->render()
        .'<a href="'.route('agent.sellers.index').'" class="btn btn-outline-light btn-sm ms-1">'.e(__('app.back')).'</a>',
])

<div class="panel-form-section">
    <form method="POST" action="{{ route('agent.sellers.update', $seller) }}">
        @csrf
        <div class="row">
            @include('admin.users._form', ['user' => $seller])
            @include('shared.users._package_assignment')
            <x-form.actions>
                <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
                <x-button :href="route('agent.sellers.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</div>
@endsection
