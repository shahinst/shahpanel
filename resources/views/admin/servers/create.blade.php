@extends('layouts.panel')

@section('page_title', __('servers.create'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('servers.create'),
    'subtitle' => __('ui.servers_create_subtitle'),
    'icon' => 'bx-plus-circle',
    'tone' => 'admin',
])

<div class="panel-modern-card">
    <div class="card-body">
        <form method="POST" action="{{ route('admin.servers.store') }}">
            @csrf
            @include('admin.servers._form')
        </form>
    </div>
</div>
@endsection
