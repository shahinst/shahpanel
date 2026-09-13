@extends('layouts.panel')

@section('page_title', __('broadcasts.send_new'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('broadcasts.send_new'),
    'subtitle' => __('broadcasts.agent_hint'),
    'icon' => 'bx-broadcast',
])

<div class="panel-form-section">
    <form method="POST" action="{{ route('agent.broadcasts.store') }}">
        @csrf
        <div class="row">
            <x-form.group wide :label="__('broadcasts.title')">
                <input name="title" required class="form-control">
            </x-form.group>
            <x-form.group wide :label="__('broadcasts.body')">
                <textarea name="body" rows="4" required class="form-control"></textarea>
            </x-form.group>
            <x-form.group wide :label="__('broadcasts.link')">
                <input name="link" class="form-control">
            </x-form.group>
            <x-form.actions>
                <x-button type="submit"><i class="bx bx-send"></i> {{ __('broadcasts.submit_for_review') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</div>
@endsection
