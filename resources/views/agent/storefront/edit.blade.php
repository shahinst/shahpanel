@extends('layouts.panel')

@section('page_title', __('menu.storefront'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('menu.storefront'),
    'subtitle' => __('storefront.settings_subtitle'),
    'icon' => 'bx-store-alt',
])

@include('shared.storefront-form', ['storefront' => $storefront, 'action' => route('agent.storefront.update')])
@endsection
