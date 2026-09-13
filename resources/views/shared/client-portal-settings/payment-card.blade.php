@extends('layouts.panel')

@section('page_title', __('clients.payment_card_settings'))

@section('panel_content')
<x-card>
    <p class="text-muted">{{ __('clients.payment_card_settings_hint') }}</p>
    <form method="POST" action="{{ route($panel.'.client-payment-card.update') }}">
        @csrf
        @method('PUT')
        <x-form.group label="شماره کارت">
            <input name="card_number" value="{{ old('card_number', $card->card_number ?? '') }}" required class="form-control">
        </x-form.group>
        <x-form.group label="صاحب کارت">
            <input name="card_holder" value="{{ old('card_holder', $card->card_holder ?? '') }}" class="form-control">
        </x-form.group>
        <x-form.group label="نام بانک">
            <input name="bank_name" value="{{ old('bank_name', $card->bank_name ?? '') }}" class="form-control">
        </x-form.group>
        <x-form.group label="توضیحات واریز">
            <textarea name="instructions" class="form-control" rows="3">{{ old('instructions', $card->instructions ?? '') }}</textarea>
        </x-form.group>
        <x-form.group label="فعال">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $card->is_active ?? true))>
        </x-form.group>
        <x-form.actions>
            <x-button type="submit">{{ __('app.save') }}</x-button>
        </x-form.actions>
    </form>
</x-card>
@endsection
