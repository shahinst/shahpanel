@extends('layouts.panel')

@section('page_title', __('packages.category_edit'))

@section('panel_content')
<x-page-header :title="__('packages.category_edit')">
    <x-slot:actions>
        <x-button :href="route('admin.packages.index', ['tab' => 'categories'])" variant="secondary">{{ __('app.back') }}</x-button>
    </x-slot:actions>
</x-page-header>
<x-card>
    <form method="POST" action="{{ route('admin.package-categories.update', $category) }}">
        @csrf
        @method('PUT')
        <div class="row">
            @include('admin.package-categories._form', ['category' => $category])
            <x-form.actions>
                <x-button type="submit">{{ __('app.save') }}</x-button>
                <x-button :href="route('admin.packages.index', ['tab' => 'categories'])" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</x-card>
@endsection
