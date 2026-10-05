@extends('layouts.panel')

@section('page_title', $package ? __('dedicated::admin.edit_package') : __('dedicated::admin.new_package'))

@section('panel_content')
{{-- The admin's own package page; the controller hands it only this agent's servers and inbounds. --}}
@include('admin.packages._page', [
    'title' => $package ? __('dedicated::admin.edit_package').' — '.$package->name : __('dedicated::admin.new_package'),
    'intro' => __('dedicated::admin.package_page_intro'),
    'icon' => $package ? 'bx-edit-alt' : 'bx-package',
    'chips' => [
        ['bx-server', __('dedicated::admin.package_chip_servers', ['count' => persian_digits($servers->count())])],
        ['bx-lock-alt', __('dedicated::admin.package_chip_private')],
        ['bx-wallet', __('dedicated::admin.package_chip_prices')],
    ],
    'action' => $package ? route('agent.dedicated.packages.update', $package) : route('agent.dedicated.packages.store'),
    'isEdit' => (bool) $package,
    'backUrl' => url()->previous(),
    'saveNote' => __('dedicated::admin.package_save_note'),
    'form' => ['servers' => $servers, 'package' => $package, 'hideKyc' => true],
])
@endsection
