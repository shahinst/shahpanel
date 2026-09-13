@extends('layouts.panel')

@section('page_title', __('menu.payment_requests'))

@section('panel_content')
@include('shared.payment-request-show', [
    'paymentRequest' => $paymentRequest,
    'approveRoute' => route('agent.payment-requests.approve', $paymentRequest),
    'rejectRoute' => route('agent.payment-requests.reject', $paymentRequest),
    'requesterBalance' => $requesterBalance ?? null,
])
@endsection
