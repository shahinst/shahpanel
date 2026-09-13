@extends('layouts.panel')

@section('page_title', 'اکانت‌های در حال انقضا')

@section('panel_content')
@php
    $thresholdLabel = format_data_size($thresholdBytes);
@endphp

@include('partials.panel-page-hero', [
    'title' => 'اکانت‌های در حال انقضا',
    'subtitle' => 'اکانت‌هایی که تا '.persian_digits($thresholdDays).' روز آینده منقضی می‌شوند یا کمتر از '.$thresholdLabel.' حجم دارند',
    'icon' => 'bx-time-five',
])

<div class="panel-modern-card">
    <div class="card-body">
        <form method="GET" class="panel-filter-bar mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">نام یا آی‌پی</label>
                    <input type="search" name="search" value="{{ request('search') }}" class="form-control form-control-sm"
                           placeholder="نام نمایشی، نام کاربری یا آی‌پی...">
                </div>
                <div class="col-md-3">
                    <label class="form-label">نوع اکانت</label>
                    <select name="service_type" class="form-select form-select-sm">
                        <option value="">همه‌ی نوع‌ها</option>
                        @foreach ($serviceTypeOptions as $type)
                            <option value="{{ $type->value }}" @selected(request('service_type') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">دلیل</label>
                    <select name="reason" class="form-select form-select-sm">
                        <option value="">انقضا یا حجم</option>
                        <option value="expiry" @selected(request('reason') === 'expiry')>فقط نزدیک انقضا</option>
                        <option value="volume" @selected(request('reason') === 'volume')>فقط حجم کم</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bx bx-filter-alt"></i> فیلتر</button>
                    <a href="{{ route($prefix.'.accounts.expiring') }}" class="btn btn-outline-secondary btn-sm">حذف</a>
                </div>
            </div>
        </form>

        <p class="text-muted small">
            مجموع: <strong>{{ persian_digits($accounts->total()) }}</strong> اکانت
        </p>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        @if ($showOwnerColumn)<th>مالک</th>@endif
                        <th>اکانت</th>
                        <th>پکیج</th>
                        <th>انقضا</th>
                        <th>حجم باقی‌مانده</th>
                        <th>وضعیت</th>
                        <th>سرور</th>
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
                                            {{ persian_digits($daysLeft) }} روز مانده
                                        @elseif ($hoursLeft >= 1)
                                            {{ persian_digits($hoursLeft) }} ساعت مانده
                                        @else
                                            {{ persian_digits(max(1, $minutesLeft)) }} دقیقه مانده
                                        @endif
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if ($remaining === null)
                                    <span class="text-muted">نامحدود</span>
                                @else
                                    <span @class(['text-danger fw-semibold' => $lowVolume])>{{ format_data_size($remaining) }}</span>
                                    @if ($lowVolume)
                                        <br><span class="badge bg-warning text-dark">حجم کم</span>
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
                                    هیچ اکانتی نزدیک انقضا یا کم‌حجم نیست. 🎉
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
