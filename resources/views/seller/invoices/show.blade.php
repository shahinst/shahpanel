@extends('layouts.panel')

@section('page_title', __('menu.invoices'))

@section('panel_content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <dl class="dl-horizontal">
                    <dt>{{ __('ui.col_invoice_number') }}</dt>
                    <dd>{{ $invoice->invoice_number }}</dd>
                    <dt>{{ __('menu.amount') }}</dt>
                    <dd>{{ format_money($invoice->total, $invoice->currency) }}</dd>
                    <dt>{{ __('app.status') }}</dt>
                    <dd>{{ $invoice->status->value }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
