@extends('layouts.panel')

@section('page_title', __('menu.invoices'))

@section('panel_content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <dl class="dl-horizontal">
                    <dt>شماره</dt>
                    <dd>{{ $invoice->invoice_number }}</dd>
                    <dt>مبلغ</dt>
                    <dd>{{ format_toman($invoice->total) }}</dd>
                    <dt>وضعیت</dt>
                    <dd>{{ $invoice->status->value }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
