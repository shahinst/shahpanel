@extends('layouts.panel')

@section('page_title', __('sellers.create'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('sellers.create'),
    'subtitle' => 'افزودن فروشنده جدید به زیرمجموعه',
    'icon' => 'bx-user-plus',
])

<div class="panel-form-section">
    <form method="POST" action="{{ route('agent.sellers.store') }}">
        @csrf
        <div class="row">
            @include('admin.users._form')
            @include('shared.users._package_assignment')
            <x-form.actions>
                <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
                <x-button :href="route('agent.sellers.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</div>
@endsection
