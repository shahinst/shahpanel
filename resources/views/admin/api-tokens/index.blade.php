@extends('layouts.panel')

@section('page_title', __('api.admin_title'))

@section('panel_content')
<div class="row g-3 mb-3">
    @php
        $cards = [
            ['label' => __('api.admin_total_tokens'), 'value' => $stats['total'], 'icon' => 'bx-key'],
            ['label' => __('api.admin_active_tokens'), 'value' => $stats['active'], 'icon' => 'bx-check-shield'],
            ['label' => __('api.admin_users_with_api'), 'value' => $stats['users'], 'icon' => 'bx-user'],
            ['label' => __('api.admin_used_last_24h'), 'value' => $stats['used_24h'], 'icon' => 'bx-time'],
            ['label' => __('api.admin_requests_total'), 'value' => $stats['requests'], 'icon' => 'bx-transfer'],
        ];
    @endphp
    @foreach ($cards as $c)
        <div class="col-6 col-md">
            <div class="panel-modern-card h-100">
                <div class="card-body text-center py-3">
                    <i class="bx {{ $c['icon'] }} fs-3 text-primary"></i>
                    <div class="fs-4 fw-bold">{{ persian_digits(number_format($c['value'])) }}</div>
                    <div class="small text-muted">{{ $c['label'] }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="panel-modern-card">
    <div class="card-head">
        <h3><i class="bx bx-plug"></i> {{ __('api.admin_title') }}</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small">{{ __('api.admin_intro') }}</p>

        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control form-control-sm"
                       value="{{ $filters['search'] ?? '' }}"
                       placeholder="{{ __('api.admin_filter_user') }}">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">{{ __('api.admin_all') }}</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('api.ui_active') }}</option>
                    <option value="revoked" @selected(($filters['status'] ?? '') === 'revoked')>{{ __('api.ui_revoked') }}</option>
                    <option value="expired" @selected(($filters['status'] ?? '') === 'expired')>{{ __('api.ui_expired') }}</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100"><i class="bx bx-search"></i></button>
            </div>
        </form>

        @if ($tokens->isEmpty())
            <div class="alert alert-info mb-0">{{ __('api.admin_no_tokens') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('api.admin_user') }}</th>
                            <th>{{ __('api.admin_token') }}</th>
                            <th>{{ __('api.ui_abilities') }}</th>
                            <th>{{ __('api.ui_last_used') }}</th>
                            <th>{{ __('api.admin_last_ip') }}</th>
                            <th>{{ __('api.ui_requests') }}</th>
                            <th>{{ __('api.admin_rate') }}</th>
                            <th>{{ __('api.ui_status') }}</th>
                            <th style="width: 100px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr>
                                <td>
                                    @if ($token->user)
                                        <div class="fw-bold">{{ $token->user->username }}</div>
                                        <div class="small text-muted">
                                            {{ $token->user->full_name }}
                                            · {{ $token->user->role->label() }}
                                        </div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <div>{{ $token->name }}</div>
                                    <div class="small text-muted">
                                        {{ __('api.admin_created') }}:
                                        {{ persian_digits(optional($token->created_at)->format('Y/m/d')) }}
                                    </div>
                                </td>
                                <td style="max-width: 240px;">
                                    @foreach (\App\Services\ApiTokenService::abilityLabels($token->abilities) as $label)
                                        <span class="badge bg-light text-dark border mb-1">{{ $label }}</span>
                                    @endforeach
                                </td>
                                <td class="small">
                                    {{ $token->last_used_at?->diffForHumans() ?? __('api.ui_never_used') }}
                                </td>
                                <td class="small" dir="ltr">{{ $token->last_used_ip ?? '—' }}</td>
                                <td class="small">{{ persian_digits(number_format($token->request_count)) }}</td>
                                <td class="small">
                                    {{ persian_digits((string) $token->rate_limit_per_minute) }}
                                    @if (filled($token->allowed_ips))
                                        <div class="text-muted" dir="ltr" style="font-size: 11px;">
                                            {{ \Illuminate\Support\Str::limit($token->allowed_ips, 24) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if ($token->isRevoked())
                                        <span class="badge bg-danger">{{ __('api.ui_revoked') }}</span>
                                    @elseif ($token->isExpired())
                                        <span class="badge bg-warning text-dark">{{ __('api.ui_expired') }}</span>
                                    @else
                                        <span class="badge bg-success">{{ __('api.ui_active') }}</span>
                                    @endif
                                </td>
                                <td>
                                    @unless ($token->isRevoked())
                                        <form method="POST"
                                              action="{{ route('admin.api-tokens.destroy', $token) }}"
                                              onsubmit="return confirm('{{ __('api.admin_revoke_confirm') }}');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger">
                                                {{ __('api.ui_revoke') }}
                                            </button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $tokens->links() }}</div>
        @endif
    </div>
</div>
@endsection
