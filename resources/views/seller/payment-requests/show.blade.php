@extends('layouts.panel')

@section('page_title', __('menu.payment_requests'))

@section('panel_content')
@include('shared.payment-request-show', ['paymentRequest' => $paymentRequest])
@endsection
