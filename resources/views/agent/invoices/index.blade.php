@extends('layouts.panel')

@section('page_title', __('menu.invoices'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('menu.invoices'),
    'subtitle' => 'فاکتورهای صادر شده برای اکانت‌ها',
    'icon' => 'bx-receipt',
])

<div class="panel-modern-card">
    <div class="card-head"><h3>{{ __('menu.invoices') }}</h3></div>
    <div class="card-body">
        <x-table :headers="['شماره', 'فروشنده', 'مبلغ', 'وضعیت', __('app.actions')]">
            @forelse ($invoices as $invoice)
                <tr>
                    <td>{{ $invoice->invoice_number }}</td>
                    <td>{{ $invoice->seller?->full_name }}</td>
                    <td>{{ format_toman($invoice->total) }}</td>
                    <td>{{ $invoice->status->value }}</td>
                    <td><a href="{{ route('agent.invoices.show', $invoice) }}" class="btn btn-sm btn-primary">{{ __('app.view') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">{{ __('app.no_results') }}</td></tr>
            @endforelse
        </x-table>
    </div>
    @if ($invoices->hasPages())
        <div class="card-foot">{{ $invoices->links() }}</div>
    @endif
</div>
@endsection
