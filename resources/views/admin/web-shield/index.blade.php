@extends('layouts.panel')

@section('page_title', __('security.hub_title'))

@section('panel_content')
@include('admin.security-firewall._tabs', ['securitySection' => 'server'])

@php
    $svcBadge = static function (string $state): array {
        return match ($state) {
            'up' => ['bg-success', __('webshield.up')],
            'freshclam' => ['bg-warning text-dark', __('webshield.freshclam')],
            default => ['bg-danger', __('webshield.down')],
        };
    };
    $tabs = [
        'overview' => __('webshield.tab_overview'),
        'decisions' => __('webshield.tab_decisions'),
        'whitelist' => __('webshield.tab_whitelist'),
        'scan' => __('webshield.tab_scan'),
        'logs' => __('webshield.tab_logs'),
        'services' => __('webshield.tab_services'),
    ];
@endphp

<div class="panel-modern-card mb-3">
    <div class="card-head d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h3 class="mb-1"><i class="bx bx-shield-alt-2"></i> {{ __('security.hub_title') }}</h3>
            <p class="text-muted small mb-0">{{ __('webshield.subtitle') }}</p>
        </div>
        <form method="POST" action="{{ route('admin.web-shield.init') }}">
            @csrf
            <button class="btn btn-outline-secondary btn-sm" type="submit">{{ __('webshield.init') }}</button>
        </form>
    </div>
    <div class="card-body">
        @if (! $available)
            <div class="alert alert-warning mb-0">{{ __('webshield.helper_missing') }}</div>
        @else
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="badge bg-success">{{ __('webshield.helper_ok') }}</span>
                @if (! empty($status['egress_safe']))
                    <span class="badge bg-info text-dark">{{ __('webshield.egress_safe') }}</span>
                @endif
                <span class="badge bg-secondary">{{ __('webshield.mode') }}: {{ $status['mode'] ?? 'ip-layer' }}</span>
            </div>
            <p class="small text-muted mt-2 mb-0">{{ __('webshield.mode_note') }}</p>
        @endif
    </div>
</div>

<div class="row g-3 mb-3">
    @foreach ([
        ['label' => __('webshield.stat_crowdsec'), 'value' => $status['crowdsec'] ?? 'down', 'kind' => 'svc'],
        ['label' => __('webshield.stat_bouncer'), 'value' => $status['bouncer'] ?? 'down', 'kind' => 'svc'],
        ['label' => __('webshield.stat_fail2ban'), 'value' => $status['fail2ban'] ?? 'down', 'kind' => 'svc'],
        ['label' => __('webshield.stat_clamav'), 'value' => $status['clamav'] ?? 'down', 'kind' => 'svc'],
        ['label' => __('webshield.stat_modsecurity'), 'value' => $status['modsecurity'] ?? 'down', 'kind' => 'svc'],
        ['label' => __('webshield.stat_decisions'), 'value' => (int) ($status['decisions'] ?? 0), 'kind' => 'num'],
        ['label' => __('webshield.stat_panel_bans'), 'value' => (int) ($status['panel_bans'] ?? 0), 'kind' => 'num'],
        ['label' => __('webshield.stat_danger'), 'value' => (int) ($status['danger_events'] ?? 0), 'kind' => 'num'],
    ] as $card)
        <div class="col-6 col-md-3">
            <div class="panel-modern-card h-100">
                <div class="card-body">
                    <div class="label small mb-1">{{ $card['label'] }}</div>
                    @if ($card['kind'] === 'svc')
                        @php [$cls, $txt] = $svcBadge((string) $card['value']); @endphp
                        <span class="badge {{ $cls }}">{{ $txt }}</span>
                    @else
                        <div class="fs-4 fw-bold">{{ persian_digits((string) $card['value']) }}</div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>

<ul class="nav nav-pills flex-wrap gap-1 mb-3">
    @foreach ($tabs as $key => $label)
        <li class="nav-item">
            <a class="nav-link {{ $tab === $key ? 'active' : '' }}"
               href="{{ route('admin.web-shield.index', ['tab' => $key]) }}">{{ $label }}</a>
        </li>
    @endforeach
</ul>

@if ($tab === 'overview')
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="panel-modern-card h-100">
                <div class="card-head"><h3>{{ __('webshield.alerts') }}</h3></div>
                <div class="card-body">
                    @if (empty($alerts))
                        <p class="text-muted mb-0">{{ __('webshield.no_alerts') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>{{ __('webshield.col_ip') }}</th>
                                    <th>{{ __('webshield.col_code') }}</th>
                                    <th>{{ __('webshield.col_count') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach (array_slice($alerts, 0, 15) as $row)
                                    <tr>
                                        <td><x-ip-with-flag :ip="$row['ip'] ?? ''" /></td>
                                        <td>{{ persian_digits((string) ($row['code'] ?? '')) }}</td>
                                        <td>{{ persian_digits((string) ($row['count'] ?? 0)) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="panel-modern-card h-100">
                <div class="card-head"><h3>{{ __('webshield.tab_decisions') }}</h3></div>
                <div class="card-body">
                    @if (empty($decisions))
                        <p class="text-muted mb-0">{{ __('webshield.no_decisions') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>{{ __('webshield.col_ip') }}</th>
                                    <th>{{ __('webshield.col_reason') }}</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach (array_slice($decisions, 0, 10) as $row)
                                    <tr>
                                        <td><x-ip-with-flag :ip="$row['ip'] ?? ''" /></td>
                                        <td class="small">{{ $row['reason'] ?? '' }}</td>
                                        <td class="text-end">
                                            @if (! empty($row['ip']))
                                                <form method="POST" action="{{ route('admin.web-shield.unban') }}" class="d-inline">
                                                    @csrf
                                                    <input type="hidden" name="ip" value="{{ $row['ip'] }}">
                                                    <button class="btn btn-sm btn-outline-success" type="submit">{{ __('webshield.unban_action') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif

@if ($tab === 'decisions')
    <div class="panel-modern-card mb-3">
        <div class="card-head"><h3>{{ __('webshield.ban_title') }}</h3></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.web-shield.ban') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-4">
                    <label class="form-label">{{ __('webshield.col_ip') }}</label>
                    <input type="text" name="ip" class="form-control" dir="ltr" required placeholder="1.2.3.4">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('webshield.ban_seconds') }}</label>
                    <input type="number" name="seconds" class="form-control" value="3600" min="60" max="31536000">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-danger" type="submit">{{ __('webshield.ban_action') }}</button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel-modern-card">
        <div class="card-head"><h3>{{ __('webshield.tab_decisions') }}</h3></div>
        <div class="card-body">
            @if (empty($decisions))
                <p class="text-muted mb-0">{{ __('webshield.no_decisions') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>{{ __('webshield.col_ip') }}</th>
                            <th>{{ __('webshield.col_source') }}</th>
                            <th>{{ __('webshield.col_reason') }}</th>
                            <th>{{ __('webshield.col_type') }}</th>
                            <th>{{ __('webshield.col_until') }}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($decisions as $row)
                            <tr>
                                <td><x-ip-with-flag :ip="$row['ip'] ?? ''" /></td>
                                <td>{{ $row['source'] ?? '' }}</td>
                                <td class="small">{{ $row['reason'] ?? '' }}</td>
                                <td>{{ $row['type'] ?? '' }}</td>
                                <td dir="ltr" class="small">{{ $row['until'] ?? '' }}</td>
                                <td class="text-end">
                                    @if (! empty($row['ip']))
                                        <form method="POST" action="{{ route('admin.web-shield.unban') }}">
                                            @csrf
                                            <input type="hidden" name="ip" value="{{ $row['ip'] }}">
                                            <button class="btn btn-sm btn-outline-success" type="submit">{{ __('webshield.unban_action') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endif

@if ($tab === 'whitelist')
    <div class="panel-modern-card mb-3">
        <div class="card-head d-flex justify-content-between flex-wrap gap-2">
            <h3>{{ __('webshield.whitelist_title') }}</h3>
            <div class="d-flex gap-2 flex-wrap">
                <form method="POST" action="{{ route('admin.web-shield.whitelist.me') }}">
                    @csrf
                    <button class="btn btn-sm btn-primary" type="submit">{{ __('webshield.whitelist_me') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.web-shield.whitelist.sync') }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary" type="submit">{{ __('webshield.whitelist_sync') }}</button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <p class="small text-muted">{{ __('webshield.your_ip') }}: <x-ip-with-flag :ip="$clientIp" /></p>
            <form method="POST" action="{{ route('admin.web-shield.whitelist.add') }}" class="row g-2 align-items-end mb-3">
                @csrf
                <div class="col-md-4">
                    <label class="form-label">{{ __('webshield.col_ip') }}</label>
                    <input type="text" name="ip" class="form-control" dir="ltr" required>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-success" type="submit">{{ __('webshield.whitelist_add') }}</button>
                </div>
            </form>

            @if (empty($whitelist))
                <p class="text-muted">{{ __('webshield.no_whitelist') }}</p>
            @else
                <ul class="list-group list-group-flush">
                    @foreach ($whitelist as $ip)
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <x-ip-with-flag :ip="$ip" />
                            <form method="POST" action="{{ route('admin.web-shield.whitelist.del') }}">
                                @csrf
                                <input type="hidden" name="ip" value="{{ $ip }}">
                                <button class="btn btn-sm btn-outline-danger" type="submit">×</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="panel-modern-card">
        <div class="card-head"><h3>{{ __('webshield.db_whitelist') }}</h3></div>
        <div class="card-body">
            @if ($dbWhitelist->isEmpty())
                <p class="text-muted mb-0">{{ __('webshield.no_whitelist') }}</p>
            @else
                <ul class="mb-0">
                    @foreach ($dbWhitelist as $entry)
                        <li>
                            <x-ip-with-flag :ip="$entry->ip" />
                            @if($entry->note)<span class="text-muted">— {{ $entry->note }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif

@if ($tab === 'scan')
    <div class="panel-modern-card">
        <div class="card-head d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h3>{{ __('webshield.scan_title') }}</h3>
            <form method="POST" action="{{ route('admin.web-shield.scan') }}">
                @csrf
                <button class="btn btn-primary btn-sm" type="submit" @disabled(!empty($scan['status']) && $scan['status'] === 'running')>
                    {{ __('webshield.scan_start') }}
                </button>
            </form>
        </div>
        <div class="card-body">
            @if (! empty($scan['status']) && $scan['status'] === 'running')
                <div class="alert alert-info">{{ __('webshield.scan_running') }}</div>
            @elseif (empty($scan['started_at']) && empty($scan['finished_at']))
                <p class="text-muted">{{ __('webshield.scan_idle') }}</p>
            @else
                <div class="mb-2">
                    <strong>{{ __('webshield.scan_finished') }}:</strong>
                    <span dir="ltr">{{ $scan['finished_at'] ?? $scan['started_at'] ?? '-' }}</span>
                </div>
                <div class="mb-3">
                    {{ __('webshield.scanned_files') }}:
                    <strong>{{ persian_digits((string) ($scan['scanned_files'] ?? 0)) }}</strong>
                    —
                    {{ __('webshield.stat_infected') }}:
                    <strong class="{{ ((int) ($scan['infected'] ?? 0)) > 0 ? 'text-danger' : 'text-success' }}">
                        {{ persian_digits((string) ($scan['infected'] ?? 0)) }}
                    </strong>
                </div>
            @endif

            <h4 class="h6">{{ __('webshield.findings') }}</h4>
            @if (empty($scan['findings']))
                <p class="text-muted mb-0">{{ __('webshield.no_findings') }}</p>
            @else
                <pre class="bg-dark text-light p-3 rounded small" dir="ltr" style="max-height: 360px; overflow:auto;">{{ implode("\n", $scan['findings']) }}</pre>
            @endif
        </div>
    </div>
@endif

@if ($tab === 'logs')
    <div class="panel-modern-card">
        <div class="card-head"><h3>{{ __('webshield.logs_title') }}</h3></div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 mb-3">
                @foreach ([
                    'crowdsec' => __('webshield.log_crowdsec'),
                    'nginx' => __('webshield.log_nginx'),
                    'nginx-error' => __('webshield.log_nginx_error'),
                    'fail2ban' => __('webshield.log_fail2ban'),
                    'clamav' => __('webshield.log_clamav'),
                    'modsec' => __('webshield.log_modsec'),
                    'modsec-danger' => __('webshield.log_modsec_danger'),
                    'audit' => __('webshield.log_audit'),
                ] as $key => $label)
                    <a href="{{ route('admin.web-shield.index', ['tab' => 'logs', 'log' => $key]) }}"
                       class="btn btn-sm {{ $logKind === $key ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $label }}</a>
                @endforeach
            </div>
            @if (! empty($logs['file']))
                <p class="small text-muted" dir="ltr">{{ $logs['file'] }}</p>
            @endif
            <pre class="bg-dark text-light p-3 rounded small mb-0" dir="ltr" style="max-height: 520px; overflow:auto;">{{ implode("\n", $logs['lines'] ?? []) }}</pre>
        </div>
    </div>
@endif

@if ($tab === 'services')
    <div class="panel-modern-card">
        <div class="card-head"><h3>{{ __('webshield.services_title') }}</h3></div>
        <div class="card-body">
            <p class="small text-muted">{{ __('webshield.services_hint') }}</p>
            <div class="table-responsive">
                <table class="table align-middle">
                    <tbody>
                    @foreach ([
                        'crowdsec' => __('webshield.stat_crowdsec'),
                        'crowdsec-firewall-bouncer' => __('webshield.stat_bouncer'),
                        'fail2ban' => __('webshield.stat_fail2ban'),
                        'clamav-daemon' => __('webshield.stat_clamav'),
                        'clamav-freshclam' => 'freshclam',
                        'nginx' => 'Nginx',
                    ] as $svc => $label)
                        <tr>
                            <th style="width: 220px;">{{ $label }}</th>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach (['restart' => __('webshield.restart'), 'reload' => __('webshield.reload'), 'start' => __('webshield.start')] as $act => $actLabel)
                                        <form method="POST" action="{{ route('admin.web-shield.service') }}">
                                            @csrf
                                            <input type="hidden" name="service" value="{{ $svc }}">
                                            <input type="hidden" name="action" value="{{ $act }}">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">{{ $actLabel }}</button>
                                        </form>
                                    @endforeach
                                    @if ($svc !== 'nginx')
                                        <form method="POST" action="{{ route('admin.web-shield.service') }}">
                                            @csrf
                                            <input type="hidden" name="service" value="{{ $svc }}">
                                            <input type="hidden" name="action" value="stop">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">{{ __('webshield.stop') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
@endsection
