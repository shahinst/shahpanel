@extends('layouts.panel')

@section('page_title', __('menu.accounts'))

@section('panel_content')
@php
    $canRenew = ! $account->isRefunded()
        && $account->package !== null
        && app(\App\Services\PackageCategoryService::class)->isPackageAvailableForRenewal($account->package);
@endphp

@if ($canRenew)
<div class="mb-3">
    <x-card>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h5 class="mb-1"><i class="bx bx-revision"></i> {{ __('menu.renew') }}</h5>
                <p class="text-muted small mb-0">
                    @if ($account->status === \App\Enums\AccountStatus::Expired)
                        {{ __('ui.account_renew_expired_notice', [':owner' => $account->ownerSeller?->full_name ?? '—']) }}
                    @else
                        {{ __('ui.account_renew_notice', [':owner' => $account->ownerSeller?->full_name ?? '—']) }}
                    @endif
                </p>
            </div>
            <x-button :href="route('admin.accounts.renew-form', $account)" variant="success">
                <i class="bx bx-revision"></i> {{ __('menu.renew') }}
            </x-button>
        </div>
    </x-card>
</div>
@endif

<x-card>
    <form method="POST" action="{{ route('admin.accounts.update', $account) }}">
        @csrf
        @method('PUT')
        <div class="row">
            @include('agent.accounts._form', [
                'account' => $account,
                'servers' => $servers,
                'canTransferServer' => true,
            ])

            <x-form.group label="{{ __('accounts.display_label') }}" hint="{{ __('accounts.display_label_hint') }}">
                <input name="display_label" value="{{ old('display_label', $account->display_label) }}" class="form-control" maxlength="255">
            </x-form.group>

            @if ($purchaseInvoice)
            <x-form.group label="{{ __('accounts.staff_charge') }}" hint="{{ __('accounts.staff_charge_hint') }}">
                <input
                    type="number"
                    name="staff_charge"
                    class="form-control"
                    min="0"
                    step="1"
                    value="{{ old('staff_charge', (int) $purchaseInvoice->total) }}"
                >
            </x-form.group>
            @endif

            <x-form.group label="{{ __('accounts.expiry') }}" hint="{{ __('accounts.admin_expiry_edit_hint') }}" wide>
                @if ($account->expiry_at)
                    <p class="text-muted small mb-2">{{ __('accounts.current_expiry') }}: <strong>{{ jalali_date($account->expiry_at, 'Y/m/d H:i') }}</strong></p>
                @else
                    <p class="text-muted small mb-2">{{ __('accounts.current_expiry') }}: <strong>{{ __('accounts.no_expiry') }}</strong></p>
                @endif

                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" name="expiry_unlimited" id="expiry-unlimited" value="1"
                           @checked(old('expiry_unlimited'))>
                    <label class="form-check-label" for="expiry-unlimited">{{ __('accounts.expiry_unlimited') }}</label>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('accounts.expiry_new_date') }}</label>
                        <x-form.jalali-date name="expiry_jalali" :value="old('expiry_jalali', $account->expiry_at ? jalali_date_input($account->expiry_at) : '')" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('accounts.expiry_add_days') }}</label>
                        <input type="number" name="expiry_add_days" class="form-control" min="-3650" max="3650"
                               value="{{ old('expiry_add_days') }}" placeholder="0">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('accounts.expiry_add_months') }}</label>
                        <input type="number" name="expiry_add_months" class="form-control" min="-120" max="120"
                               value="{{ old('expiry_add_months') }}" placeholder="0">
                    </div>
                </div>
                <p class="form-text text-muted small mb-0 mt-2">{{ __('accounts.admin_expiry_edit_priority_hint') }}</p>
            </x-form.group>

            <x-form.actions>
                <x-button type="submit">{{ __('app.save') }}</x-button>
                <x-button :href="route('admin.accounts.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
            </x-form.actions>
        </div>
    </form>
</x-card>
@endsection
