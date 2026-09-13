@extends('layouts.panel')

@section('page_title', __('clients.charge_wallet'))

@section('panel_content')
@include('client.partials.payment-card-box', [
    'owner' => $owner,
    'ownerRoleLabel' => $ownerRoleLabel,
    'cards' => $cards ?? null,
    'showActions' => true,
])

<x-card :title="__('clients.charge_wallet')">
    <p class="text-muted small mb-3">{{ __('clients.charge_form_hint') }}</p>

    <form method="POST" action="{{ route('client.payment-requests.store') }}">
        @csrf
        <div class="row">
            <div class="col-md-6">
                <x-form.group :label="__('menu.amount')">
                    <input type="number" name="amount" min="1000" step="1000" required class="form-control" value="{{ old('amount') }}" placeholder="100000">
                </x-form.group>
            </div>
            <div class="col-md-6">
                <x-form.group :label="__('menu.tracking_number')">
                    <input name="tracking_number" required class="form-control" value="{{ old('tracking_number') }}" placeholder="{{ __('clients.tracking_placeholder') }}">
                </x-form.group>
            </div>
            <div class="col-md-6">
                <x-form.group :label="__('menu.card_last4')">
                    <input name="card_last4" maxlength="4" minlength="4" pattern="[0-9]{4}" inputmode="numeric" required class="form-control" value="{{ old('card_last4') }}" placeholder="1234">
                </x-form.group>
            </div>
            <div class="col-md-6">
                <x-form.group :label="__('clients.requester_note')">
                    <input name="requester_note" maxlength="1000" class="form-control" value="{{ old('requester_note') }}">
                </x-form.group>
            </div>
        </div>
        <x-form.actions>
            <x-button type="submit">{{ __('clients.submit_charge_request') }}</x-button>
            <x-button :href="route('client.payment-requests.index')" variant="secondary">{{ __('app.back') }}</x-button>
        </x-form.actions>
    </form>
</x-card>
@endsection
