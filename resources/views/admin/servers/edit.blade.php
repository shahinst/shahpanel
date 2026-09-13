@extends('layouts.panel')

@section('page_title', __('app.edit').' — '.$server->name)

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('app.edit').': '.$server->name,
    'subtitle' => $server->host.':'.persian_digits($server->port),
    'icon' => 'bx-edit',
    'actions' => '<a href="'.route('admin.servers.show', $server).'" class="btn btn-light btn-sm"><i class="bx bx-arrow-back"></i> '.e(__('servers.manage')).'</a>',
])

<div class="panel-modern-card">
    <div class="card-body">
        <form method="POST" action="{{ route('admin.servers.update', $server) }}">
            @csrf
            @method('PUT')
            @include('admin.servers._form', ['server' => $server])
        </form>
    </div>
</div>
@endsection
