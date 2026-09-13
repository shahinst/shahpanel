@php
    $routePanel = explode('.', request()->route()?->getName() ?? '')[0] ?? 'admin';
    $exportRoute = $routePanel.'.accounting.export';
    $filterQuery = request()->only(['date_from', 'date_to', 'search', 'status']);
    // Sellers never earn sales profit, so the credited/profit column is hidden for them.
    $showCredited = ($totals['role'] ?? null) !== \App\Enums\UserRole::Seller;
    $showMarginPercent = $showMarginPercent ?? false;
@endphp

@include('partials.panel-page-hero', [
    'title' => __('menu.accounting'),
    'subtitle' => __('accounting.subtitle'),
    'icon' => 'bx-calculator',
])

@if ($serverChangesRemaining !== null)
    <x-alert type="info" class="mb-3">
        {{ __('accounting.server_changes_remaining', ['count' => persian_digits($serverChangesRemaining)]) }}
    </x-alert>
@endif

@if ($showCredited)
    <x-alert type="info" class="mb-3">
        {{ __('accounting.hierarchy_hint') }}
    </x-alert>
@endif

@if ($showCredited && $showMarginPercent)
    <x-alert type="info" class="mb-3">
        {{ __('accounting.margin_percent_hint') }}
    </x-alert>
@endif

<div class="row g-3 mb-3">
    <div class="{{ $showCredited ? 'col-md-6' : 'col-md-12' }}">
        <div class="panel-kpi-mini">
            <div class="label">{{ __('accounting.total_debited') }}</div>
            <p class="value">{{ format_toman($totals['debited'] ?? 0) }}</p>
        </div>
    </div>
    @if ($showCredited)
        <div class="col-md-6">
            <div class="panel-kpi-mini">
                <div class="label">{{ __('accounting.total_credited') }}</div>
                <p class="value">{{ format_toman($totals['credited'] ?? 0) }}</p>
            </div>
        </div>
    @endif
</div>

<div class="panel-modern-card">
    <div class="card-head">
        <h3>{{ __('menu.accounting') }}</h3>
        <div class="panel-export-group d-flex flex-wrap gap-2">
            <a href="{{ route($exportRoute, array_merge(['format' => 'csv'], $filterQuery)) }}" class="btn btn-sm btn-success">
                <i class="bx bx-spreadsheet align-middle"></i> {{ __('accounting.export_excel') }}
            </a>
            <a href="{{ route($exportRoute, array_merge(['format' => 'pdf'], $filterQuery)) }}" class="btn btn-sm btn-danger">
                <i class="bx bxs-file-pdf align-middle"></i> {{ __('accounting.export_pdf') }}
            </a>
            <a href="{{ route($exportRoute, ['format' => 'csv']) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bx bx-download align-middle"></i> {{ __('accounting.export_all') }}
            </a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="panel-filter-bar">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">{{ __('accounting.date_from') }}</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('accounting.date_to') }}</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('accounting.search_account') }}</label>
                    <input type="search" name="search" value="{{ request('search') }}" class="form-control form-control-sm" placeholder="{{ __('app.search') }}...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ __('accounting.account_status') }}</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">{{ __('accounting.all_statuses') }}</option>
                        @foreach (['active', 'disabled', 'expired', 'exhausted', 'deleted'] as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>
                                {{ $status === 'deleted' ? __('accounting.deleted') : __("accounts.status_{$status}") }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="bx bx-filter-alt"></i> {{ __('accounting.apply_filter') }}
                    </button>
                    <a href="{{ route($routePanel.'.accounting.index') }}" class="btn btn-outline-secondary btn-sm">
                        {{ __('accounting.reset_filter') }}
                    </a>
                </div>
            </div>
            @if ($filterQuery !== [])
                <p class="text-muted small mb-0 mt-2">{{ __('accounting.export_filtered') }}</p>
            @endif
        </form>

        <x-table :headers="array_values(array_filter([
            __('accounting.transaction_at'),
            __('accounting.event_type'),
            __('accounting.account_name'),
            __('accounting.service_type'),
            __('accounting.account_status'),
            __('accounting.expiry_at'),
            __('accounting.package'),
            $showCredited ? __('accounting.amount_credited') : null,
            $showMarginPercent ? __('accounting.margin_percent') : null,
            __('accounting.amount_deducted'),
        ], fn ($value) => $value !== null))">
            @forelse ($entries as $invoice)
                @php
                    $account = $invoice->account;
                    $row = $summary[$invoice->id] ?? ['debited' => '0', 'credited' => '0'];
                    $eventLabel = $eventLabels[$invoice->id] ?? '—';
                @endphp
                @continue($account === null)
                @php
                    $statusLabel = $account->trashed()
                        ? __('accounting.deleted')
                        : ($account->isRefunded()
                            ? __('accounts.status_refunded')
                            : match ($account->status->value) {
                                'active' => __('accounts.status_active'),
                                'disabled' => __('accounts.status_disabled'),
                                'expired' => __('accounts.status_expired'),
                                'exhausted' => __('accounts.status_exhausted'),
                                default => $account->status->value,
                            });
                @endphp
                <tr @class(['table-secondary' => $account->trashed()])>
                    <td>{{ jalali_date($invoice->issued_at ?? $invoice->created_at) }}</td>
                    <td><span class="badge bg-{{ $invoice->type->value === 'new_account' ? 'primary' : 'info' }}">{{ $eventLabel }}</span></td>
                    <td>{{ $routePanel === 'admin' ? $account->remote_username : ($account->display_label ?: $account->remote_username) }}</td>
                    <td>{{ $account->service_type->label() }}</td>
                    <td>{{ $statusLabel }}</td>
                    <td>{{ $account->expiry_at ? jalali_date($account->expiry_at, 'Y/m/d') : '—' }}</td>
                    <td>{{ $account->package?->name ?? '—' }}</td>
                    @if ($showCredited)
                        <td>{{ format_toman($row['credited']) }}</td>
                    @endif
                    @if ($showMarginPercent)
                        <td>
                            @if (! empty($row['margin_percent']))
                                {{ persian_digits($row['margin_percent']) }}٪
                            @else
                                —
                            @endif
                        </td>
                    @endif
                    <td>{{ format_toman($row['debited']) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ 8 + ($showCredited ? 1 : 0) + ($showMarginPercent ? 1 : 0) }}" class="text-center text-muted py-4">{{ __('app.no_results') }}</td></tr>
            @endforelse
        </x-table>
    </div>
    @if ($entries->hasPages())
        <div class="card-foot">{{ $entries->links() }}</div>
    @endif
</div>
