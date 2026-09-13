@extends('layouts.panel')

@section('page_title', __('accounts.config_title'))

@section('panel_content')
<p><a href="{{ route($prefix.'.accounts.'.$account->service_type->accountCategory()->value) }}"><i class="bx bx-arrow-back"></i> {{ $account->service_type->accountCategory()->label() }}</a></p>

<div class="row">
    <div class="col-md-5 text-center">
        <x-card :title="$account->remote_username">
            <img src="data:image/png;base64,{{ $qrBase64 }}" alt="QR" class="img-fluid mx-auto d-block" style="max-width:260px;padding:10px;">
            <p style="margin-top:15px;">
                <x-button :href="route($prefix.'.accounts.config.qr', $account)" size="sm">{{ __('accounts.download_qr') }}</x-button>
                <x-button :href="route($prefix.'.accounts.config.download', $account)" variant="secondary" size="sm">{{ __('accounts.download_config') }}</x-button>
            </p>
        </x-card>
    </div>
    <div class="col-md-7">
        <x-card :title="__('accounts.config_title')">
            <pre dir="ltr" class="bg-light p-3 rounded small" style="max-height:400px;overflow:auto;margin:0;">{{ $config }}</pre>
        </x-card>
    </div>
</div>
@endsection
