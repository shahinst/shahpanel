@extends('layouts.panel')

@section('page_title', __('tickets.new_ticket'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('tickets.new_ticket'),
    'subtitle' => __('tickets.subtitle'),
    'icon' => 'bx-plus-circle',
])

<div class="panel-form-section">
    <form method="POST" action="{{ route($panel.'.tickets.store') }}">
        @csrf
        <x-form.group :label="__('tickets.subject')">
            <input name="subject" value="{{ old('subject') }}" required class="form-control">
        </x-form.group>
        @if ($departments->isNotEmpty())
            <x-form.group :label="__('tickets.department')">
                <select name="department_id" class="form-select">
                    <option value="">{{ __('tickets.select_department') }}</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </x-form.group>
        @endif
        <x-form.group wide :label="__('tickets.body')">
            <textarea name="body" rows="6" required class="form-control">{{ old('body') }}</textarea>
        </x-form.group>
        <x-form.actions>
            <x-button type="submit">{{ __('tickets.new_ticket') }}</x-button>
            <x-button :href="route($panel.'.tickets.index')" variant="secondary">{{ __('app.back') }}</x-button>
        </x-form.actions>
    </form>
</div>
@endsection
