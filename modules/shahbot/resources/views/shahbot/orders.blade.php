@extends('layouts.panel')

@section('page_title', __('shahbot::admin.tab_orders'))

@section('panel_content')
@include('shahbot::_nav')

<div class="sb-filters">
    @foreach (['' => __('shahbot::admin.all'), 'buy' => __('shahbot::admin.order_buy'), 'renew' => __('shahbot::admin.order_renew'), 'test' => __('shahbot::admin.order_test')] as $value => $label)
        <a href="{{ route('admin.shahbot.orders', array_filter(['type' => $value])) }}" @class(['is-active' => request('type', '') === $value])>{{ $label }}</a>
    @endforeach
</div>

<div class="sb-box">
    @include('shahbot::_orders_table', ['orders' => $orders])
</div>
{{ $orders->links() }}
@endsection
