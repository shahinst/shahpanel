@extends('layouts.panel')

@section('page_title', __('menu.packages'))

@section('panel_content')
@include('admin.packages._page', [
    'title' => __('packages.edit').' — '.$package->name,
    'intro' => __('packages.page_intro_edit'),
    'icon' => 'bx-edit-alt',
    'chips' => [
        ['bx-server', __('packages.chip_servers', ['count' => persian_digits($servers->count())])],
        ['bx-time-five', __('packages.chip_durations')],
        ['bx-category', __('packages.chip_categories', ['count' => persian_digits($categories->count())])],
    ],
    'action' => route('admin.packages.update', $package),
    'isEdit' => true,
    'backUrl' => route('admin.packages.index'),
    'saveNote' => __('packages.save_note'),
    'form' => ['package' => $package, 'servers' => $servers, 'durationsByTier' => $durationsByTier],
])
@endsection
