@extends('layouts.panel')

@section('page_title', __('menu.new_charge_request'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('menu.new_charge_request'),
    'subtitle' => __('ui.payment_requests_create_subtitle'),
    'icon' => 'bx-wallet',
])

@include('shared.partials.upline-payment-cards', ['cards' => $payeeCards, 'payee' => $payee ?? null])

<div class="panel-form-section">
    <form method="POST" action="{{ route('agent.payment-requests.store') }}">
        @csrf
        <div class="row">
            @include('shared.payment-request-form')
            <x-form.actions>
                <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</div>
@endsection
