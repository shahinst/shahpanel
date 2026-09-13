@extends('layouts.panel')

@section('page_title', __('accounting_corrections.page_title'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('accounting_corrections.page_title'),
    'subtitle' => __('accounting_corrections.subtitle'),
    'icon' => 'bx-error-circle',
])

<x-alert type="warning" class="mb-3">
    {{ __('accounting_corrections.banner', [
        'days' => persian_digits((string) ($daysRemaining ?? 0)),
        'date' => $expiresAt ? jalali_date($expiresAt, 'Y/m/d') : '—',
    ]) }}
</x-alert>

<div class="panel-kpi-mini mb-3">
    <div class="label">{{ __('accounting_corrections.total_clawback') }}</div>
    <p class="value">{{ format_toman($totalClawback) }}</p>
</div>

<div class="panel-modern-card">
    <div class="card-body">
        @if ($corrections->isEmpty())
            <p class="text-muted mb-0">{{ __('accounting_corrections.empty') }}</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>{{ __('accounting_corrections.date') }}</th>
                            <th>{{ __('accounting_corrections.account') }}</th>
                            <th>{{ __('accounting_corrections.package') }}</th>
                            <th>{{ __('accounting_corrections.actual_margin') }}</th>
                            <th>{{ __('accounting_corrections.expected_margin') }}</th>
                            <th>{{ __('accounting_corrections.clawback') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($corrections as $row)
                            <tr>
                                <td>{{ jalali_date($row->created_at) }}</td>
                                <td><code>{{ $row->account?->remote_username ?? '—' }}</code></td>
                                <td>{{ $row->account?->package?->name ?? '—' }}</td>
                                <td>{{ format_toman($row->actual_margin) }}</td>
                                <td>{{ format_toman($row->expected_margin) }}</td>
                                <td><strong class="text-danger">{{ format_toman($row->clawback_amount) }}</strong></td>
                            </tr>
                            @if ($row->reason)
                                <tr>
                                    <td colspan="6" class="text-muted small pb-3">{{ $row->reason }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p class="text-muted small mt-3 mb-0">{{ __('accounting_corrections.footer') }}</p>
    </div>
</div>
@endsection
