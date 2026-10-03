@extends('layouts.panel')

@section('page_title', __('shahbot::admin.tab_refunds'))

@section('panel_content')
@include('shahbot::_nav')

<p class="sb-muted">{{ __('shahbot::admin.refund_hint') }}</p>
<div class="sb-filters">
    @foreach (['pending', 'approved', 'rejected', 'all'] as $value)
        <a href="{{ route('admin.shahbot.refunds.index', ['status' => $value]) }}" @class(['is-active' => $status === $value])>{{ $value === 'all' ? __('shahbot::admin.all') : __('shahbot::admin.agency_status_'.$value) }}</a>
    @endforeach
</div>

<div class="sb-box">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>#</th><th>{{ __('shahbot::admin.col_user') }}</th><th>{{ __('shahbot::admin.col_account') }}</th><th>{{ __('shahbot::admin.note_col') }}</th><th>{{ __('shahbot::admin.col_status') }}</th><th>{{ __('shahbot::admin.col_date') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($requests as $refund)
                    <tr>
                        <td>{{ persian_digits($refund->id) }}</td>
                        <td><a href="{{ route('admin.shahbot.users.show', $refund->botUser) }}">{{ $refund->botUser->displayName() }}</a></td>
                        <td>@if ($refund->account)<a href="{{ route('admin.accounts.show', $refund->account) }}">{{ $refund->account->remote_username }}</a><div class="sb-muted">{{ $refund->account->package?->name }} · {{ $refund->account->expiry_at ? jalali_date($refund->account->expiry_at, 'Y/m/d') : '—' }}</div>@endif</td>
                        <td style="max-width:300px;white-space:pre-wrap">{{ $refund->reason }}</td>
                        <td>
                            <span @class(['sb-pill', 'warn' => $refund->status === 'pending', 'ok' => $refund->status === 'approved', 'bad' => $refund->status === 'rejected'])>{{ __('shahbot::admin.agency_status_'.$refund->status) }}</span>
                            @if ($refund->amount !== null)<div class="sb-muted">{{ format_money($refund->amount) }}</div>@endif
                            @if ($refund->reviewed_by)<div class="sb-muted">{{ $refund->reviewed_by }}</div>@endif
                        </td>
                        <td>{{ jalali_date($refund->created_at, 'Y/m/d H:i') }}</td>
                        <td class="d-flex gap-1">
                            @if ($refund->status === 'pending')
                                <x-icon-action icon="bx-check" variant="success" :label="__('shahbot::admin.approve_refund')" :action="route('admin.shahbot.refunds.approve', $refund)" :confirm="__('shahbot::admin.approve_refund').'?'" />
                                <x-icon-action icon="bx-x" variant="danger" :label="__('shahbot::admin.reject')" :action="route('admin.shahbot.refunds.reject', $refund)" />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $requests->links() }}
@endsection
