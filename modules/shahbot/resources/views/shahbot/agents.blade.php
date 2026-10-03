@extends('layouts.panel')

@section('page_title', __('shahbot::admin.tab_agents'))

@section('panel_content')
@include('shahbot::_nav')

<div class="sb-box">
    <header>{{ __('shahbot::admin.agency_requests') }}</header>
    <div class="sb-body">
        <div class="sb-filters">
            @foreach (['pending', 'approved', 'rejected', 'all'] as $value)
                <a href="{{ route('admin.shahbot.agents.index', ['status' => $value]) }}" @class(['is-active' => $status === $value])>{{ $value === 'all' ? __('shahbot::admin.all') : __('shahbot::admin.agency_status_'.$value) }}</a>
            @endforeach
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>#</th><th>{{ __('shahbot::admin.col_user') }}</th><th>{{ __('shahbot::admin.note_col') }}</th><th>{{ __('shahbot::admin.col_status') }}</th><th>{{ __('shahbot::admin.seller_account') }}</th><th>{{ __('shahbot::admin.col_date') }}</th><th></th></tr></thead>
                <tbody>
                    @forelse ($requests as $agencyRequest)
                        <tr>
                            <td>{{ persian_digits($agencyRequest->id) }}</td>
                            <td><a href="{{ route('admin.shahbot.users.show', $agencyRequest->botUser) }}">{{ $agencyRequest->botUser->displayName() }}</a>@if ($agencyRequest->botUser->phone)<div class="sb-muted">{{ persian_digits($agencyRequest->botUser->phone) }}</div>@endif</td>
                            <td style="max-width:320px;white-space:pre-wrap">{{ $agencyRequest->note }}</td>
                            <td><span @class(['sb-pill', 'warn' => $agencyRequest->status === 'pending', 'ok' => $agencyRequest->status === 'approved', 'bad' => $agencyRequest->status === 'rejected'])>{{ __('shahbot::admin.agency_status_'.$agencyRequest->status) }}</span>@if ($agencyRequest->reviewed_by)<div class="sb-muted">{{ $agencyRequest->reviewed_by }}</div>@endif</td>
                            <td>@if ($agencyRequest->seller)<a href="{{ route('admin.sellers.edit', $agencyRequest->seller) }}">{{ $agencyRequest->seller->username }}</a>@else — @endif</td>
                            <td>{{ jalali_date($agencyRequest->created_at, 'Y/m/d H:i') }}</td>
                            <td class="d-flex gap-1">
                                @if ($agencyRequest->status === 'pending')
                                    <x-icon-action icon="bx-check" variant="success" :label="__('shahbot::admin.approve')" :action="route('admin.shahbot.agents.approve', $agencyRequest)" :confirm="__('shahbot::admin.approve').'?'" />
                                    <x-icon-action icon="bx-x" variant="danger" :label="__('shahbot::admin.reject')" :action="route('admin.shahbot.agents.reject', $agencyRequest)" />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $requests->links() }}
    </div>
</div>

<div class="sb-box">
    <header>{{ __('shahbot::admin.agent_bots') }}</header>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>{{ __('shahbot::admin.bot_owner') }}</th><th>{{ __('shahbot::admin.bot_link') }}</th><th>{{ __('shahbot::admin.bot_users') }}</th><th>{{ __('shahbot::admin.bot_sales') }}</th><th>{{ __('shahbot::admin.col_status') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($bots as $bot)
                    <tr>
                        <td>{{ $bot->owner?->full_name ?: $bot->owner?->username }} <span class="sb-muted">{{ $bot->owner?->role->label() }}</span></td>
                        <td>@if ($bot->username)<a href="https://t.me/{{ $bot->username }}" target="_blank" rel="noopener">{{ '@'.$bot->username }}</a>@else — @endif</td>
                        <td>{{ persian_digits($bot->users_count) }}</td>
                        <td>{{ format_money($bot->sales_total) }}</td>
                        <td><span @class(['sb-pill', 'ok' => $bot->is_active, 'bad' => ! $bot->is_active])>{{ $bot->is_active ? __('shahbot::admin.active') : __('shahbot::admin.inactive') }}</span></td>
                        <td><x-icon-action icon="bx-power-off" :label="$bot->is_active ? __('shahbot::admin.disable') : __('shahbot::admin.enable')" :action="route('admin.shahbot.agents.bots.toggle', $bot)" /></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
