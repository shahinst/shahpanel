@extends('layouts.panel')

@section('page_title', __('shahbot::admin.title'))

@section('panel_content')
@include('shahbot::_nav')

@unless ($configured)
    <x-alert type="warning" class="mb-3">{{ __('shahbot::admin.not_configured') }} <a href="{{ route('admin.shahbot.settings') }}">{{ __('shahbot::admin.tab_settings') }}</a></x-alert>
@endunless

<div class="sb-cards">
    <div class="sb-card"><i class="bx bx-group" style="--tone:#229ED9"></i><div><strong>{{ persian_digits(number_format($stats['users'])) }}</strong><span>{{ __('shahbot::admin.stat_users') }} · {{ __('shahbot::admin.stat_users_today', ['count' => persian_digits($stats['users_today'])]) }}</span></div></div>
    <div class="sb-card"><i class="bx bx-user-check" style="--tone:#10b981"></i><div><strong>{{ persian_digits(number_format($stats['customers'])) }}</strong><span>{{ __('shahbot::admin.stat_customers') }} · {{ __('shahbot::admin.stat_blocked', ['count' => persian_digits($stats['blocked'])]) }}</span></div></div>
    <div class="sb-card"><i class="bx bx-cart" style="--tone:#f59e0b"></i><div><strong>{{ format_money($stats['sales_today']) }}</strong><span>{{ __('shahbot::admin.stat_sales_today') }} · {{ __('shahbot::admin.stat_orders', ['count' => persian_digits($stats['orders_today'])]) }}</span></div></div>
    <div class="sb-card"><i class="bx bx-line-chart" style="--tone:#6366f1"></i><div><strong>{{ format_money($stats['sales_30']) }}</strong><span>{{ __('shahbot::admin.stat_sales_30') }} · {{ __('shahbot::admin.stat_orders', ['count' => persian_digits($stats['orders_30'])]) }}</span></div></div>
    <a class="sb-card text-reset text-decoration-none" href="{{ route('admin.shahbot.payments.index') }}"><i class="bx bx-receipt" style="--tone:#ef4444"></i><div><strong>{{ persian_digits($stats['pending_payments']) }}</strong><span>{{ __('shahbot::admin.stat_pending') }} · {{ __('shahbot::admin.stat_tickets', ['count' => persian_digits($stats['open_tickets'])]) }}</span></div></a>
    <div class="sb-card"><i class="bx bx-test-tube" style="--tone:#14b8a6"></i><div><strong>{{ persian_digits($stats['tests']) }}</strong><span>{{ __('shahbot::admin.stat_tests') }}</span></div></div>
</div>

<div class="sb-box">
    <header>
        <span>{{ __('shahbot::admin.chart_title') }}</span>
        @if ($settings->get('bot_username'))
            <a href="https://t.me/{{ $settings->get('bot_username') }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bx bxl-telegram"></i> {{ __('shahbot::admin.bot_link') }} {{ '@'.$settings->get('bot_username') }}</a>
        @endif
    </header>
    <div class="sb-body">
        @php $max = max(1, $chart->max('total')); @endphp
        <div class="sb-chart">
            @foreach ($chart as $day)
                <div title="{{ format_money($day['total']) }}">
                    <b style="height: {{ round($day['total'] / $max * 100, 1) }}%"></b>
                    <small>{{ persian_digits($day['label']) }}</small>
                </div>
            @endforeach
        </div>
    </div>
</div>

<div class="sb-box">
    <header>
        <span>{{ __('shahbot::admin.recent_orders') }}</span>
        <a href="{{ route('admin.shahbot.orders') }}" class="btn btn-sm btn-light">{{ __('shahbot::admin.view_all') }}</a>
    </header>
    @include('shahbot::_orders_table', ['orders' => $recentOrders])
</div>
@endsection
