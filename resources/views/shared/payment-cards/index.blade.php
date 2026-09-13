@extends('layouts.panel')

@section('page_title', __('clients.payment_card_settings'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('clients.payment_card_settings'),
    'subtitle' => __('payment_cards.settings_subtitle'),
    'icon' => 'bx-credit-card',
])

<div class="row g-3">
    <div class="col-lg-5">
        <div class="panel-form-section">
            <h4 class="panel-form-section-title">{{ __('payment_cards.add_card') }}</h4>
            @if ($panel !== 'admin')
                <p class="text-muted small">{{ __('payment_cards.client_visibility_hint') }}</p>
            @endif
            @if ($uplineLabel && $panel !== 'admin')
                <p class="text-muted small">{{ __('payment_cards.upline_approval_hint', ['role' => $uplineLabel]) }}</p>
            @endif
            <form method="POST" action="{{ route($panel.'.client-payment-card.update') }}">
                @csrf
                @method('PUT')
                <x-form.group :label="__('clients.card_number')">
                    <input name="card_number" value="{{ old('card_number') }}" required class="form-control" dir="ltr">
                </x-form.group>
                <x-form.group :label="__('clients.card_holder')">
                    <input name="card_holder" value="{{ old('card_holder') }}" class="form-control">
                </x-form.group>
                <x-form.group :label="__('clients.bank_name')">
                    <input name="bank_name" value="{{ old('bank_name') }}" class="form-control">
                </x-form.group>
                <x-form.group :label="__('payment_cards.instructions')">
                    <textarea name="instructions" class="form-control" rows="3">{{ old('instructions') }}</textarea>
                </x-form.group>
                <x-form.actions>
                    <x-button type="submit"><i class="bx bx-plus"></i> {{ __('payment_cards.add_card') }}</x-button>
                </x-form.actions>
            </form>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="panel-modern-card">
            <div class="card-head"><h3>{{ __('payment_cards.my_cards') }}</h3></div>
            <div class="card-body">
                @forelse ($cards as $card)
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <code dir="ltr">{{ $card->card_number }}</code>
                            <span class="badge bg-secondary">{{ $card->approval_status->label() }}</span>
                        </div>
                        @if ($card->bank_name)<div class="small text-muted">{{ $card->bank_name }}</div>@endif
                        @if ($card->card_holder)<div class="small">{{ $card->card_holder }}</div>@endif
                        @if ($card->isPendingDeletion())
                            <div class="alert alert-warning small py-2 mt-2 mb-2">{{ __('payment_cards.pending_deletion') }}</div>
                        @endif
                        @if ($card->approval_status->value === 'pending')
                            <div class="alert alert-info small py-2 mt-2 mb-2">{{ __('payment_cards.awaiting_upline') }}</div>
                        @endif
                        @if ($panel === 'admin' || ($card->isApproved() && ! $card->isPendingDeletion()))
                            <form method="POST" action="{{ route($panel.'.payment-cards.destroy', $card) }}" class="d-inline" onsubmit="return confirm(@json(__('payment_cards.confirm_delete')));">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('payment_cards.request_delete') }}</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">{{ __('payment_cards.no_cards') }}</p>
                @endforelse
            </div>
        </div>

        @if ($pendingApprovals->isNotEmpty())
            <div class="panel-modern-card mt-3">
                <div class="card-head"><h3>{{ __('payment_cards.pending_approvals') }}</h3></div>
                <div class="card-body">
                    @foreach ($pendingApprovals as $pending)
                        <div class="border rounded p-3 mb-3">
                            <div class="fw-semibold">{{ $pending->user->full_name }} ({{ $pending->user->role->label() }})</div>
                            <code dir="ltr" class="d-block my-2">{{ $pending->card_number }}</code>
                            @if ($pending->isPendingDeletion())
                                <span class="badge bg-warning">{{ __('payment_cards.delete_request') }}</span>
                            @else
                                <span class="badge bg-info">{{ __('payment_cards.add_request') }}</span>
                            @endif
                            <div class="d-flex gap-2 mt-2">
                                <form method="POST" action="{{ route($panel.'.payment-cards.approve', $pending) }}">@csrf<button class="btn btn-sm btn-success">{{ __('menu.approve') }}</button></form>
                                <form method="POST" action="{{ route($panel.'.payment-cards.reject', $pending) }}">@csrf<button class="btn btn-sm btn-outline-secondary">{{ __('menu.reject') }}</button></form>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
