@extends('layouts.panel')

@section('page_title', __('packages.create'))

@section('panel_content')
@include('admin.packages._page', [
    'title' => __('packages.create'),
    'intro' => __('packages.page_intro_create'),
    'icon' => 'bx-package',
    'chips' => [
        ['bx-server', __('packages.chip_servers', ['count' => persian_digits($servers->count())])],
        ['bx-time-five', __('packages.chip_durations')],
        ['bx-category', __('packages.chip_categories', ['count' => persian_digits($categories->count())])],
    ],
    'action' => route('admin.packages.store'),
    'isEdit' => false,
    'backUrl' => route('admin.packages.index'),
    'saveNote' => __('packages.save_note'),
    'form' => ['servers' => $servers],
])
@endsection
