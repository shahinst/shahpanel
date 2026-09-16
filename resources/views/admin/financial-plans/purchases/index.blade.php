@extends('layouts.panel')

@section('page_title', __('financial_plans.page_title_purchases'))

@section('panel_content')
@php
    // جدول طرح‌های مالی ستون ارز ندارد؛ این طرح‌ها از کیف‌پول ارز پیش‌فرض پنل خرید و تسویه می‌شوند.
    $planCurrency = \App\Enums\MoneyCurrency::default();
@endphp
@include('partials.panel-page-hero', [
    'title' => __('financial_plans.page_title_purchases'),
    'subtitle' => __('financial_plans.fifo_note'),
    'icon' => 'bx-transfer',
    'actions' => '<a href="'.route('admin.agent-financial-plans.create').'" class="btn btn-primary btn-sm"><i class="bx bx-plus"></i> '.__('financial_plans.sell_plan').'</a>',
])

@if (session('success'))
    <x-alert type="success" class="mb-3">{{ session('success') }}</x-alert>
@endif
@if (session('error'))
    <x-alert type="danger" class="mb-3">{{ session('error') }}</x-alert>
@endif

<div class="panel-modern-card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">{{ __('financial_plans.agent') }}</label>
                <select name="agent_id" class="form-select form-select-sm">
                    <option value="">{{ __('app.search') }}...</option>
                    @foreach ($agents as $agent)
                        <option value="{{ $agent->id }}" @selected(request('agent_id') == $agent->id)>{{ $agent->full_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm">{{ __('accounting.apply_filter') }}</button>
            </div>
        </form>
    </div>
</div>

<div class="panel-modern-card">
    <div class="card-body">
        <x-table :headers="[
            __('financial_plans.purchased_at'),
            __('financial_plans.agent'),
            __('financial_plans.name'),
            __('financial_plans.credit_total'),
            __('financial_plans.credit_remaining'),
            __('financial_plans.discount_percent'),
            __('financial_plans.status'),
            __('financial_plans.sold_by'),
        ]">
            @forelse ($purchases as $purchase)
                <tr>
                    <td>{{ jalali_date($purchase->purchased_at) }}</td>
                    <td>{{ $purchase->agent?->full_name ?? '—' }}</td>
                    <td>{{ $purchase->name }}</td>
                    <td>{{ format_money($purchase->credit_total, $planCurrency) }}</td>
                    <td>{{ format_money($purchase->credit_remaining, $planCurrency) }}</td>
                    <td>{{ persian_digits(number_format((float) $purchase->discount_percent, 2)) }}٪</td>
                    <td>{{ $purchase->status->label() }}</td>
                    <td>{{ $purchase->soldBy?->full_name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">{{ __('financial_plans.no_purchases') }}</td></tr>
            @endforelse
        </x-table>
    </div>
    @if ($purchases->hasPages())
        <div class="card-foot">{{ $purchases->links() }}</div>
    @endif
</div>
@endsection
