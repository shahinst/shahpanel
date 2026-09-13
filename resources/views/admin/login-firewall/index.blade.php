@extends('layouts.panel')

@section('page_title', __('security.hub_title'))

@section('panel_content')
@include('admin.security-firewall._tabs', ['securitySection' => 'login'])

<div class="row g-3 mb-3">
    @foreach ([
        ['label' => __('loginfw.stat_active'), 'value' => $stats['active'], 'icon' => 'bx-block', 'tone' => 'danger'],
        ['label' => __('loginfw.stat_in_firewall'), 'value' => $stats['in_firewall'], 'icon' => 'bx-shield-x', 'tone' => 'warning'],
        ['label' => __('loginfw.stat_failures'), 'value' => $stats['failures_24h'], 'icon' => 'bx-key', 'tone' => 'secondary'],
        ['label' => __('loginfw.stat_total'), 'value' => $stats['total'], 'icon' => 'bx-history', 'tone' => 'info'],
    ] as $card)
        <div class="col-6 col-lg-3">
            <div class="panel-modern-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bx {{ $card['icon'] }} fs-3 text-{{ $card['tone'] }}"></i>
                    <div>
                        <div class="fs-4 fw-bold">{{ persian_digits((string) $card['value']) }}</div>
                        <div class="label small">{{ $card['label'] }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="panel-modern-card mb-3">
    <div class="card-head">
        <h3><i class="bx bx-shield-quarter"></i> {{ __('loginfw.firewall_state') }}</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <tbody>
                    <tr>
                        <th style="width: 230px;">{{ __('loginfw.fw_helper') }}</th>
                        <td>
                            @if ($firewallStatus['available'])
                                <span class="badge bg-success">{{ __('loginfw.fw_ok') }}</span>
                            @else
                                <span class="badge bg-danger">{{ __('loginfw.fw_missing') }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>{{ __('loginfw.fw_chain') }}</th>
                        <td>
                            @if ($firewallStatus['chain'] === 'hooked')
                                <span class="badge bg-success">{{ __('loginfw.fw_hooked') }}</span>
                            @else
                                <span class="badge bg-warning text-dark">{{ $firewallStatus['chain'] }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>{{ __('loginfw.fw_country_ranges') }}</th>
                        <td>
                            🇨🇳 {{ persian_digits(number_format($firewallStatus['cn'])) }}
                            <span class="text-muted small">{{ __('loginfw.fw_country_note') }}</span>
                        </td>
                    </tr>
                    <tr>
                        <th>{{ __('loginfw.fw_policy') }}</th>
                        <td class="small">
                            {{ __('loginfw.fw_policy_note', [
                                'attempts' => persian_digits((string) $settings['max_attempts']),
                                'window' => persian_digits((string) $settings['window']),
                                'minutes' => persian_digits((string) $settings['block_minutes']),
                            ]) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

@if ($topCountries->isNotEmpty())
    <div class="panel-modern-card mb-3">
        <div class="card-head">
            <h3><i class="bx bx-world"></i> {{ __('loginfw.by_country') }}</h3>
        </div>
        <div class="card-body d-flex flex-wrap gap-2">
            @foreach ($topCountries as $row)
                @php
                    $tmp = new \App\Models\BlockedIp(['country_code' => $row->country_code]);
                @endphp
                <a href="{{ route('admin.login-firewall.index', ['country' => $row->country_code, 'state' => $state]) }}"
                   class="btn btn-sm btn-outline-secondary">
                    <span class="fs-5">{{ $tmp->flag() }}</span>
                    {{ \App\Services\GeoIpService::countryName($row->country_code) }}
                    <span class="badge bg-secondary">{{ persian_digits((string) $row->total) }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif

<div class="panel-modern-card mb-3">
    <div class="card-head d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h3 class="mb-0"><i class="bx bx-list-ul"></i> {{ __('loginfw.blocked_list') }}</h3>
        <form method="GET" action="{{ route('admin.login-firewall.index') }}" class="d-flex gap-2 flex-wrap">
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
                   class="form-control form-control-sm" style="width: 180px;"
                   placeholder="{{ __('loginfw.search_placeholder') }}">
            <select name="state" class="form-select form-select-sm" style="width: 130px;">
                <option value="active" @selected($state === 'active')>{{ __('loginfw.state_active') }}</option>
                <option value="lifted" @selected($state === 'lifted')>{{ __('loginfw.state_lifted') }}</option>
                <option value="all" @selected($state === 'all')>{{ __('loginfw.state_all') }}</option>
            </select>
            <button class="btn btn-sm btn-primary">{{ __('loginfw.filter') }}</button>
        </form>
    </div>
    <div class="card-body">
        @if ($blocks->isEmpty())
            <div class="alert alert-info mb-0">{{ __('loginfw.no_blocks') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('loginfw.col_country') }}</th>
                            <th>{{ __('loginfw.col_ip') }}</th>
                            <th>{{ __('loginfw.col_reason') }}</th>
                            <th>{{ __('loginfw.col_username') }}</th>
                            <th>{{ __('loginfw.col_attempts') }}</th>
                            <th>{{ __('loginfw.col_blocked_at') }}</th>
                            <th>{{ __('loginfw.col_expires') }}</th>
                            <th>{{ __('loginfw.col_state') }}</th>
                            <th style="width: 110px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($blocks as $block)
                            <tr>
                                <td>
                                    <span class="fs-5">{{ $block->flag() }}</span>
                                    <span class="small text-muted">{{ $block->country_name ?? '—' }}</span>
                                </td>
                                <td>
                                    <x-ip-with-flag :ip="$block->ip" :flag="$block->flag()" />
                                </td>
                                <td class="small">{{ __('loginfw.reason_'.$block->reason) }}</td>
                                <td class="small" dir="ltr">{{ $block->last_username ?? '—' }}</td>
                                <td class="small">{{ persian_digits((string) $block->attempts) }}</td>
                                <td class="small">{{ $block->blocked_at?->diffForHumans() ?? '—' }}</td>
                                <td class="small">
                                    @if ($block->expires_at === null)
                                        <span class="text-danger">{{ __('loginfw.never_expires') }}</span>
                                    @else
                                        {{ $block->expires_at->diffForHumans() }}
                                    @endif
                                </td>
                                <td>
                                    @if (! $block->isActive())
                                        <span class="badge bg-secondary">{{ __('loginfw.state_lifted') }}</span>
                                    @elseif ($block->in_firewall)
                                        <span class="badge bg-danger">{{ __('loginfw.in_firewall') }}</span>
                                    @else
                                        <span class="badge bg-warning text-dark">{{ __('loginfw.login_only') }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($block->isActive())
                                        <form method="POST" action="{{ route('admin.login-firewall.unblock', $block) }}"
                                              onsubmit="return confirm('{{ __('loginfw.unblock_confirm') }}');">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-success">
                                                <i class="bx bx-lock-open"></i> {{ __('loginfw.unblock') }}
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $blocks->links() }}
        @endif
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="panel-modern-card h-100">
            <div class="card-head">
                <h3><i class="bx bx-block"></i> {{ __('loginfw.manual_block') }}</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.login-firewall.block') }}">
                    @csrf
                    <div class="mb-2">
                        <label class="form-label">{{ __('loginfw.col_ip') }}</label>
                        <input type="text" name="ip" class="form-control" dir="ltr" required placeholder="1.2.3.4">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('loginfw.duration_minutes') }}</label>
                        <input type="number" name="minutes" class="form-control" min="0" value="60">
                        <div class="form-text">{{ __('loginfw.duration_hint') }}</div>
                    </div>
                    <button class="btn btn-danger btn-sm">{{ __('loginfw.block_now') }}</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="panel-modern-card h-100">
            <div class="card-head">
                <h3><i class="bx bx-shield-plus"></i> {{ __('loginfw.whitelist') }}</h3>
            </div>
            <div class="card-body">
                <p class="text-muted small">{{ __('loginfw.whitelist_hint') }}</p>
                <form method="POST" action="{{ route('admin.login-firewall.whitelist.store') }}" class="mb-3">
                    @csrf
                    <div class="d-flex gap-2 flex-wrap">
                        <input type="text" name="ip" class="form-control" dir="ltr"
                               style="max-width: 190px;" required placeholder="1.2.3.4 {{ __('loginfw.or') }} 1.2.3.0/24">
                        <input type="text" name="note" class="form-control" style="max-width: 190px;"
                               placeholder="{{ __('loginfw.note') }}">
                        <button class="btn btn-success btn-sm">{{ __('loginfw.add') }}</button>
                    </div>
                </form>

                @if ($whitelist->isEmpty())
                    <div class="text-muted small">{{ __('loginfw.whitelist_empty') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <tbody>
                                @foreach ($whitelist as $entry)
                                    <tr>
                                        <td><x-ip-with-flag :ip="$entry->ip" /></td>
                                        <td class="small text-muted">{{ $entry->note ?? '—' }}</td>
                                        <td style="width: 80px;">
                                            <form method="POST"
                                                  action="{{ route('admin.login-firewall.whitelist.destroy', $entry) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">
                                                    {{ __('loginfw.remove') }}
                                                </button>
                                            </form>
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
@endsection
