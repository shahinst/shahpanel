@extends('layouts.panel')

@section('page_title', __('financial_plans.page_title_agent'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('financial_plans.page_title_agent'),
    'subtitle' => __('financial_plans.agent_hint'),
    'icon' => 'bx-wallet-alt',
])

<x-alert type="info" class="mb-3">
    {{ __('financial_plans.active_plans_summary', [
        'count' => persian_digits((string) $preview['active_count']),
        'amount' => format_toman($preview['total_remaining']),
    ]) }}
</x-alert>

<div class="panel-modern-card">
    <div class="card-body">
        <x-table :headers="[
            __('financial_plans.purchased_at'),
            __('financial_plans.name'),
            __('financial_plans.credit_total'),
            __('financial_plans.credit_remaining'),
            __('financial_plans.discount_percent'),
            __('financial_plans.status'),
        ]">
            @forelse ($purchases as $purchase)
                <tr>
                    <td>{{ jalali_date($purchase->purchased_at) }}</td>
                    <td>{{ $purchase->name }}</td>
                    <td>{{ format_toman($purchase->credit_total) }}</td>
                    <td>{{ format_toman($purchase->credit_remaining) }}</td>
                    <td>{{ persian_digits(number_format((float) $purchase->discount_percent, 2)) }}٪</td>
                    <td>{{ $purchase->status->label() }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">{{ __('financial_plans.no_purchases') }}</td></tr>
            @endforelse
        </x-table>
    </div>
    @if ($purchases->hasPages())
        <div class="card-foot">{{ $purchases->links() }}</div>
    @endif
</div>
@endsection
