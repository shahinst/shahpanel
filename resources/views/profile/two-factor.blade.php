@extends('layouts.panel')

@php
    $routeName = request()->route()?->getName() ?? '';
    $panel = explode('.', $routeName)[0] ?: 'admin';
@endphp

@section('page_title', __('security.two_factor'))

@section('panel_content')
<x-page-header :title="__('security.two_factor')" />

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <p class="text-muted">{{ __('security.two_factor_hint') }}</p>

                @if ($enabled)
                    <div class="alert alert-success">{{ __('security.two_factor_already_enabled') }}</div>

                    <form method="POST" action="{{ route("{$panel}.two-factor.disable") }}" class="mt-3">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label" for="disable-code">{{ __('security.two_factor_code') }}</label>
                            <input type="text" class="form-control" id="disable-code" name="code"
                                   inputmode="numeric" maxlength="6" required>
                        </div>
                        <button type="submit" class="btn btn-danger">{{ __('security.two_factor_disable') }}</button>
                    </form>
                @else
                    @if ($secret && $qrUrl)
                        <p>{{ __('security.two_factor_scan_qr') }}</p>
                        <div class="mb-3 text-center">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={{ urlencode($qrUrl) }}"
                                 alt="QR" width="200" height="200" class="border rounded">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('security.two_factor_secret') }}</label>
                            <input type="text" class="form-control font-monospace" value="{{ $secret }}" readonly dir="ltr">
                        </div>
                    @endif

                    <form method="POST" action="{{ route("{$panel}.two-factor.enable") }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label" for="enable-code">{{ __('security.two_factor_code') }}</label>
                            <input type="text" class="form-control" id="enable-code" name="code"
                                   inputmode="numeric" maxlength="6" required>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('security.two_factor_enable') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
