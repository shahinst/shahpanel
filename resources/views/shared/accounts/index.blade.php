@extends('layouts.panel')

@section('page_title', $category->label())

@section('page_actions')
    @can('create', \App\Models\Account::class)
        @if (($staffCreateModal ?? false))
            <button type="button" class="btn btn-primary btn-sm" data-staff-create-account-open>
                <i class="bx bx-plus align-middle"></i> {{ __('accounts.create') }}
            </button>
        @else
            <x-button :href="route($prefix.'.accounts.create')" size="sm">
                <i class="bx bx-plus align-middle"></i> {{ __('accounts.create') }}
            </x-button>
        @endif
    @endcan
@endsection

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => $category->label(),
    'subtitle' => __('accounts.list_subtitle'),
    'icon' => 'bx-user-circle',
    'actions' => view('shared.accounts.partials.create-action', [
        'prefix' => $prefix,
        'staffCreateModal' => $staffCreateModal ?? false,
    ])->render(),
])

<div class="panel-modern-card">
    <div class="card-head"><h3>{{ $category->label() }}</h3></div>
    <div class="card-body">
        <form method="GET" class="panel-filter-bar">
            <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">{{ __('app.search') }}</label>
                        <input type="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('accounts.list_search_placeholder') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ __('app.status') }}</label>
                        <select name="status" class="form-select">
                            <option value="">{{ __('accounts.all_statuses') }}</option>
                            @foreach (['active', 'disabled', 'expired', 'exhausted'] as $status)
                                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __("accounts.status_{$status}") }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('accounts.filter_server') }}</label>
                        <select name="server_id" class="form-select">
                            <option value="">{{ __('accounts.all_servers') }}</option>
                            @foreach ($serverFilterOptions ?? [] as $server)
                                <option value="{{ $server->id }}" @selected((string) request('server_id') === (string) $server->id)>
                                    {{ $server->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    @if ($showOwnerFilter ?? false)
                        @if (($ownerFilterOptions['agents'] ?? collect())->isNotEmpty())
                            <div class="col-md-2">
                                <label class="form-label">{{ __('accounts.filter_owner_role') }}</label>
                                <select name="owner_role" class="form-select">
                                    <option value="">{{ __('accounts.all_owner_roles') }}</option>
                                    <option value="agent" @selected(request('owner_role') === 'agent')>{{ __('roles.agent') }}</option>
                                    <option value="seller" @selected(request('owner_role') === 'seller')>{{ __('roles.seller') }}</option>
                                </select>
                            </div>
                        @endif
                        <div class="col-md-3">
                            <label class="form-label">{{ __('accounts.filter_owner') }}</label>
                            <select name="owner_id" class="form-select">
                                <option value="">{{ __('accounts.all_owners') }}</option>
                                @if (($ownerFilterOptions['agents'] ?? collect())->isNotEmpty())
                                    <optgroup label="{{ __('roles.agent') }}">
                                        @foreach ($ownerFilterOptions['agents'] as $owner)
                                            <option value="{{ $owner->id }}" @selected((string) request('owner_id') === (string) $owner->id)>
                                                {{ $owner->full_name }} ({{ $owner->username }})
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                @if (($ownerFilterOptions['sellers'] ?? collect())->isNotEmpty())
                                    <optgroup label="{{ __('roles.seller') }}">
                                        @foreach ($ownerFilterOptions['sellers'] as $owner)
                                            <option value="{{ $owner->id }}" @selected((string) request('owner_id') === (string) $owner->id)>
                                                {{ $owner->full_name }} ({{ $owner->username }})
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            </select>
                        </div>
                    @endif
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100 btn-sm"><i class="bx bx-search"></i> {{ __('app.search') }}</button>
                    </div>
                </div>
        </form>

                @php
                    $isV2ray = ($category ?? null) === \App\Enums\AccountCategory::V2ray;
                    $isWireguard = ($category ?? null) === \App\Enums\AccountCategory::Wireguard;
                    $showVolumeColumns = $isV2ray || $isWireguard;
                    $headers = array_filter([
                        $showOwnerColumn ? __('accounts.owner') : null,
                        __('accounts.username'),
                        __('accounts.package'),
                        $showVolumeColumns ? __('accounts.purchased_volume') : null,
                        $showVolumeColumns ? __('accounts.remaining_volume') : null,
                        $isWireguard ? __('accounts.wireguard_ip') : null,
                        __('accounts.server'),
                        __('accounts.status'),
                        __('accounts.expiry'),
                        __('app.actions'),
                    ]);
                @endphp

                <div class="table-responsive accounts-table-wrap">
                    <table class="table table-bordered table-striped table-hover align-middle accounts-table mb-0">
                        <thead class="table-light">
                            <tr>
                                @foreach ($headers as $header)
                                    <th>{{ $header }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($accounts as $account)
                                <tr>
                                    @if ($showOwnerColumn)
                                        <td>
                                            <strong>{{ $account->ownerSeller?->full_name ?? '—' }}</strong><br>
                                            @if ($account->ownerSeller)
                                                <x-badge :label="$account->ownerSeller->role->label()" color="primary" />
                                            @endif
                                        </td>
                                    @endif
                                    <td>
                                        @if (in_array($prefix, ['admin', 'seller', 'agent'], true))
                                            <button type="button" class="btn btn-link p-0 text-start admin-account-report-trigger" data-account-report="{{ $account->id }}">
                                                @if ($prefix !== 'admin')
                                                    {{-- Agent/Seller: show only the display name (fall back to the
                                                         server username for old accounts that never got one). --}}
                                                    <strong>{{ $account->display_label ?: $account->remote_username }}</strong>
                                                @elseif ($account->display_label)
                                                    <strong>{{ $account->display_label }}</strong><br>
                                                    <small class="text-muted" dir="ltr">{{ $account->remote_username }}</small>
                                                @else
                                                    <strong>{{ $account->remote_username }}</strong>
                                                @endif
                                            </button>
                                        @elseif ($account->display_label)
                                            <strong>{{ $account->display_label }}</strong><br>
                                            <small class="text-muted" dir="ltr">{{ $account->remote_username }}</small>
                                        @else
                                            <strong>{{ $account->remote_username }}</strong>
                                        @endif
                                        <br><small class="text-muted">{{ $account->service_type->label() }}</small>
                                    </td>
                                    <td>{{ $account->package?->name ?? '—' }}</td>
                                    @if ($showVolumeColumns)
                                        <td>{{ $account->purchasedVolumeLabel() }}</td>
                                        <td>{{ $account->remainingVolumeLabel() }}</td>
                                    @endif
                                    @if ($isWireguard)
                                        <td dir="ltr">{{ $account->wireguardHostAddress() ?? '—' }}</td>
                                    @endif
                                    <td>{{ $account->server?->name ?? '—' }}</td>
                                    <td>@include('shared.accounts.status-toggle', ['account' => $account, 'prefix' => $prefix])</td>
                                    <td>@include('shared.accounts.expiry-cell', ['account' => $account])</td>
                                    <td class="accounts-actions-cell">@include('shared.accounts.account-menu', ['account' => $account, 'prefix' => $prefix])</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($headers) }}" class="text-center text-muted py-4">
                                        {{ __('app.no_results') }}
                                        @can('create', \App\Models\Account::class)
                                            @if ($staffCreateModal ?? false)
                                                — <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-staff-create-account-open>{{ __('accounts.create') }}</button>
                                            @else
                                                — <a href="{{ route($prefix.'.accounts.create') }}">{{ __('accounts.create') }}</a>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($accounts->hasPages())
                <div class="card-foot">{{ $accounts->onEachSide(4)->links() }}</div>
            @endif
</div>
@include('shared.accounts.dropdown-script')
@if ($staffCreateModal ?? false)
    @include('shared.accounts.create-modal', [
        'prefix' => $prefix,
        'accountOwners' => $accountOwners ?? collect(),
        'openCreateModal' => $openCreateModal ?? false,
    ])
@endif
@if (in_array($prefix ?? '', ['admin', 'seller', 'agent'], true))
    @include('shared.accounts.report-modal', ['reportPrefix' => $prefix])
@endif
@endsection
