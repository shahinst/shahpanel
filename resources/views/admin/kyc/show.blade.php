@extends('layouts.panel')

@section('page_title', __('kyc.list_title').' #'.$item->id)

@section('panel_content')
<x-page-header :title="$item->fullName()" :subtitle="$item->status->label()">
    <x-slot:actions>
        <x-button :href="route('admin.kyc.index')" size="sm" variant="secondary">{{ __('app.back') }}</x-button>
    </x-slot:actions>
</x-page-header>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="panel-modern-card">
            <div class="card-head"><h3>{{ __('kyc.section_fields') }}</h3></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('kyc.first_name') }}</dt><dd class="col-sm-8">{{ $item->first_name }}</dd>
                    <dt class="col-sm-4">{{ __('kyc.last_name') }}</dt><dd class="col-sm-8">{{ $item->last_name }}</dd>
                    <dt class="col-sm-4">{{ __('kyc.national_code') }}</dt><dd class="col-sm-8" dir="ltr">{{ $item->national_code }}</dd>
                    <dt class="col-sm-4">{{ __('kyc.birth_date') }}</dt><dd class="col-sm-8" dir="ltr">{{ $item->birth_date }}</dd>
                    <dt class="col-sm-4">{{ __('kyc.mobile') }}</dt><dd class="col-sm-8" dir="ltr">{{ $item->mobile ?? $item->maskedMobile() }}</dd>
                    <dt class="col-sm-4">{{ __('app.status') }}</dt><dd class="col-sm-8">{{ $item->status->label() }}</dd>
                    <dt class="col-sm-4">{{ __('ui.col_attempts') }}</dt><dd class="col-sm-8">{{ $item->verify_attempts }}/{{ $item->max_verify_attempts }}</dd>
                    <dt class="col-sm-4">{{ __('ui.col_initiated_by') }}</dt><dd class="col-sm-8">{{ $item->initiatedBy?->full_name }}</dd>
                    <dt class="col-sm-4">{{ __('roles.seller') }}</dt><dd class="col-sm-8">{{ $item->ownerSeller?->full_name ?? '—' }}</dd>
                    <dt class="col-sm-4">{{ __('accounts.package') }}</dt><dd class="col-sm-8">{{ $item->package?->name ?? '—' }}</dd>
                    <dt class="col-sm-4">{{ __('ui.col_account') }}</dt><dd class="col-sm-8">{{ $item->account_id ? '#'.$item->account_id : '—' }}</dd>
                    @if ($item->last_error)
                        <dt class="col-sm-4">{{ __('ui.last_error') }}</dt><dd class="col-sm-8 text-danger">{{ $item->last_error }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3>{{ __('kyc.document') }}</h3></div>
            <div class="card-body">
                @if ($item->has_document)
                    <p class="mb-2"><span class="badge bg-success">{{ __('kyc.has_document') }}</span>
                        {{ $item->document_original_name }}</p>
                    <a class="btn btn-primary btn-sm" href="{{ route('admin.kyc.document', $item) }}">
                        <i class="bx bx-show"></i> {{ __('kyc.view_document') }}
                    </a>
                @else
                    <p class="text-muted mb-0">{{ __('kyc.no_document') }}</p>
                @endif
            </div>
        </div>

        @if (in_array($item->status->value, ['locked', 'reset_requested', 'draft'], true))
            <div class="panel-modern-card">
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.kyc.reset', $item) }}"
                          onsubmit='return confirm(@json(__("ui.kyc_reset_confirm")))'>
                        @csrf
                        <button type="submit" class="btn btn-warning">
                            <i class="bx bx-reset"></i> {{ __('kyc.admin_resets') }}
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
