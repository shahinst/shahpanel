@extends('layouts.panel')

@section('page_title', __('ui.expiring_accounts_title'))

@section('panel_content')
@php
    $thresholdLabel = format_data_size($thresholdBytes);
@endphp

@include('partials.panel-page-hero', [
    'title' => __('ui.expiring_accounts_title'),
    'subtitle' => __('ui.expiring_accounts_subtitle', [':days' => persian_digits($thresholdDays), ':size' => $thresholdLabel]),
    'icon' => 'bx-time-five',
])

<div class="panel-modern-card">
    <div class="card-body">
        <form method="GET" class="panel-filter-bar mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">{{ __('ui.name_or_ip') }}</label>
                    <input type="search" name="search" value="{{ request('search') }}" class="form-control form-control-sm"
                           placeholder="{{ __('ui.search_account_placeholder') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('ui.account_type') }}</label>
                    <select name="service_type" class="form-select form-select-sm">
                        <option value="">{{ __('ui.all_types') }}</option>
                        @foreach ($serviceTypeOptions as $type)
                            <option value="{{ $type->value }}" @selected(request('service_type') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('ui.reason') }}</label>
                    <select name="reason" class="form-select form-select-sm">
                        <option value="">{{ __('ui.expiry_or_data') }}</option>
                        <option value="expiry" @selected(request('reason') === 'expiry')>{{ __('ui.only_near_expiry') }}</option>
                        <option value="volume" @selected(request('reason') === 'volume')>{{ __('ui.only_low_data') }}</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bx bx-filter-alt"></i> {{ __('ui.filter') }}</button>
                    <a href="{{ route($prefix.'.accounts.expiring') }}" class="btn btn-outline-secondary btn-sm">{{ __('ui.clear_filters') }}</a>
                </div>
            </div>
        </form>

        <p class="text-muted small">
            {{ __('ui.total_label') }}: <strong>{{ persian_digits($accounts->total()) }}</strong> {{ __('ui.accounts_unit') }}
        </p>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        @if ($showOwnerColumn)<th>{{ __('accounts.filter_owner') }}</th>@endif
                        <th>{{ __('ui.col_account') }}</th>
                        <th>{{ __('accounts.package') }}</th>
                        <th>{{ __('accounts.expiry') }}</th>
                        <th>{{ __('ui.col_remaining_data') }}</th>
                        <th>{{ __('app.status') }}</th>
                        <th>{{ __('accounts.server') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $account)
                        @php
                            $expiringSoon = $account->expiry_at !== null
                                && $account->expiry_at->isFuture()
                                && $account->expiry_at->lte(now()->addDays($thresholdDays));
                            // Sub-day precision: "0 روز مانده" reads like it should already
                            // be expired, when it may still have hours left.
                            $minutesLeft = $account->expiry_at !== null
                                ? (int) now()->diffInMinutes($account->expiry_at, false)
                                : null;
                            $daysLeft = $minutesLeft !== null ? intdiv($minutesLeft, 1440) : null;
                            $hoursLeft = $minutesLeft !== null ? intdiv($minutesLeft, 60) : null;
                            $remaining = $account->data_limit_bytes !== null
                                ? max(0, (int) $account->data_limit_bytes - (int) $account->data_used_bytes)
                                : null;
                            $lowVolume = $remaining !== null && $remaining < $thresholdBytes;
                        @endphp
                        <tr>
                            @if ($showOwnerColumn)
                                <td>
                                    <strong>{{ $account->ownerSeller?->full_name ?? '—' }}</strong>
                                    @if ($account->ownerSeller)
                                        <br><x-badge :label="$account->ownerSeller->role->label()" color="primary" />
                                    @endif
                                </td>
                            @endif
                            <td>
                                @if ($prefix === 'admin')
                                    <strong>{{ $account->display_label ?: $account->remote_username }}</strong>
                                    @if ($account->display_label)
                                        <br><small class="text-muted" dir="ltr">{{ $account->remote_username }}</small>
                                    @endif
                                @else
                                    <strong>{{ $account->display_label ?: $account->remote_username }}</strong>
                                @endif
                                <br><small class="text-muted">{{ $account->service_type->label() }}</small>
                                @if ($account->wireguard_address)
                                    <br><small class="text-muted" dir="ltr">{{ $account->wireguardHostAddress() ?? $account->wireguard_address }}</small>
                                @endif
                            </td>
                            <td>{{ $account->package?->name ?? '—' }}</td>
                            <td>
                                @include('shared.accounts.expiry-cell', ['account' => $account, 'expiringSoon' => $expiringSoon])
                                @if ($account->expiry_at && $expiringSoon)
                                    <span class="badge bg-danger">
                                        @if ($daysLeft >= 1)
                                            {{ __('ui.days_left', [':count' => persian_digits($daysLeft)]) }}
                                        @elseif ($hoursLeft >= 1)
                                            {{ __('ui.hours_left', [':count' => persian_digits($hoursLeft)]) }}
                                        @else
                                            {{ __('ui.minutes_left', [':count' => persian_digits(max(1, $minutesLeft))]) }}
                                        @endif
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if ($remaining === null)
                                    <span class="text-muted">{{ __('ui.unlimited') }}</span>
                                @else
                                    <span @class(['text-danger fw-semibold' => $lowVolume])>{{ format_data_size($remaining) }}</span>
                                    @if ($lowVolume)
                                        <br><span class="badge bg-warning text-dark">{{ __('ui.low_data') }}</span>
                                    @endif
                                @endif
                            </td>
                            <td>@include('shared.accounts.status-toggle', ['account' => $account, 'prefix' => $prefix])</td>
                            <td class="text-muted small">{{ $account->server?->name ?? '—' }}</td>
                            <td class="accounts-actions-cell">@include('shared.accounts.account-menu', ['account' => $account, 'prefix' => $prefix])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $showOwnerColumn ? 8 : 7 }}">
                                <div class="alert alert-success mb-0">
                                    {{ __('ui.no_expiring_accounts') }} 🎉
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($accounts->hasPages())
            {{ $accounts->links() }}
        @endif
    </div>
</div>
@include('shared.accounts.dropdown-script')
@if (in_array($prefix, ['admin', 'seller', 'agent'], true))
    @include('shared.accounts.report-modal', ['reportPrefix' => $prefix])
@endif
@endsection
