@extends('layouts.panel')

@section('page_title', __('shahbot::admin.tab_users'))

@section('panel_content')
@include('shahbot::_nav')

<form method="GET" class="d-flex flex-wrap gap-2 mb-2">
    <input type="text" name="q" value="{{ $search }}" class="form-control" style="max-width:320px" placeholder="{{ __('shahbot::admin.search_hint') }}">
    <input type="hidden" name="filter" value="{{ request('filter') }}">
    <select name="owner" class="form-select" style="max-width:220px">
        <option value="">{{ __('shahbot::admin.customers_filter_owner') }}</option>
        @foreach ($resellers ?? [] as $r)
            <option value="{{ $r->id }}" @selected((int) request('owner') === $r->id)>{{ $r->full_name ?: $r->username }} — {{ $r->role->label() }}</option>
        @endforeach
    </select>
    <select name="role" class="form-select" style="max-width:180px">
        <option value="">{{ __('shahbot::admin.customers_role') }}</option>
        <option value="agent" @selected(request('role') === 'agent')>{{ \App\Enums\UserRole::Agent->label() }}</option>
        <option value="seller" @selected(request('role') === 'seller')>{{ \App\Enums\UserRole::Seller->label() }}</option>
    </select>
    <select name="bot" class="form-select" style="max-width:160px">
        <option value="">{{ __('shahbot::admin.customers_all_bots') }}</option>
        <option value="main" @selected(request('bot') === 'main')>{{ __('shahbot::admin.customers_main_bot') }}</option>
    </select>
    <input type="date" name="from" value="{{ request('from') }}" class="form-control" style="max-width:170px" dir="ltr">
    <input type="date" name="to" value="{{ request('to') }}" class="form-control" style="max-width:170px" dir="ltr">
    <button class="btn btn-primary"><i class="bx bx-search"></i> {{ __('shahbot::admin.search') }}</button>
</form>
<div class="sb-filters">
    @foreach (['' => __('shahbot::admin.all'), 'customers' => __('shahbot::admin.filter_customers'), 'blocked' => __('shahbot::admin.filter_blocked')] as $value => $label)
        <a href="{{ route('admin.shahbot.users.index', array_filter(['filter' => $value, 'q' => $search])) }}" @class(['is-active' => request('filter', '') === $value])>{{ $label }}</a>
    @endforeach
</div>

<div class="sb-box">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>{{ __('shahbot::admin.col_user') }}</th>
                    <th>{{ __('shahbot::admin.col_telegram_id') }}</th>
                    <th>{{ __('shahbot::admin.bot_col') }}</th>
                    <th>{{ __('shahbot::admin.col_phone') }}</th>
                    <th>{{ __('shahbot::admin.col_orders') }}</th>
                    <th>{{ __('shahbot::admin.col_total') }}</th>
                    <th>{{ __('shahbot::admin.col_joined') }}</th>
                    <th>{{ __('shahbot::admin.col_status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td><a href="{{ route('admin.shahbot.users.show', $user) }}"><b>{{ $user->displayName() }}</b></a>@if ($user->username)<div class="sb-muted">{{ '@'.$user->username }}</div>@endif</td>
                        <td><code>{{ $user->telegram_id }}</code></td>
                        <td>{{ $user->bot ? ($user->bot->owner?->username ?? '#'.$user->bot_id) : __('shahbot::admin.main_bot') }}@if ($user->reseller_user_id) <span class="sb-pill info">{{ __('shahbot::admin.tab_agents') }}</span>@endif</td>
                        <td>{{ $user->phone ? persian_digits($user->phone) : '—' }}</td>
                        <td>{{ persian_digits($user->paid_orders_count) }}</td>
                        <td>{{ format_money($user->paid_total ?? 0) }}</td>
                        <td>{{ jalali_date($user->created_at, 'Y/m/d') }}</td>
                        <td>
                            @if ($user->is_blocked)<span class="sb-pill bad">{{ __('shahbot::admin.blocked') }}</span>@endif
                            @if ($user->bot_blocked_by_user)<span class="sb-pill warn">{{ __('shahbot::admin.left_bot') }}</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $users->links() }}
@endsection
