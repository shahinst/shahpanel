@extends('layouts.panel')

@section('page_title', __('menu.transactions'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('menu.transactions'),
    'subtitle' => 'تاریخچه تراکنش‌های کیف پول',
    'icon' => 'bx-transfer',
])

<div class="panel-modern-card">
    <div class="card-head"><h3>{{ __('menu.transactions') }}</h3></div>
    <div class="card-body">
        <x-table :headers="['نوع', __('menu.amount'), 'موجودی بعد', 'تاریخ']">
            @forelse ($transactions as $transaction)
                <tr>
                    <td>{{ $transaction->type->value }}</td>
                    <td>{{ format_money($transaction->amount, $transaction->currency) }}</td>
                    <td>{{ format_money($transaction->balance_after, $transaction->currency) }}</td>
                    <td>{{ jalali_date($transaction->created_at) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-4">{{ __('app.no_results') }}</td></tr>
            @endforelse
        </x-table>
    </div>
    @if ($transactions->hasPages())
        <div class="card-foot">{{ $transactions->links() }}</div>
    @endif
</div>
@endsection
