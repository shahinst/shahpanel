@extends('layouts.panel')

@section('page_title', __('api.ui_title'))

@section('panel_content')
<div class="panel-modern-card mb-3">
    <div class="card-head d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h3 class="mb-0"><i class="bx bx-plug"></i> {{ __('api.ui_title') }}</h3>
        <a href="{{ route($routePrefix.'.api-tokens.docs') }}" class="btn btn-sm btn-outline-primary">
            <i class="bx bx-book-open"></i> {{ __('api.docs_menu') }}
        </a>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-2">{{ __('api.ui_intro') }}</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <tbody>
                    <tr>
                        <th style="width: 170px;">{{ __('api.ui_base_url') }}</th>
                        <td><code dir="ltr">{{ $baseUrl }}</code></td>
                    </tr>
                    <tr>
                        <th>{{ __('api.ui_auth_header') }}</th>
                        <td><code dir="ltr">Authorization: Bearer &lt;token&gt;</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

@if (session('new_api_token'))
    <div class="alert alert-success">
        <strong>{{ __('api.ui_copy_now') }}</strong>
        <div class="mt-2">
            <input type="text" class="form-control" dir="ltr" readonly
                   value="{{ session('new_api_token') }}"
                   onclick="this.select();document.execCommand('copy');">
        </div>
        <div class="small mt-2">{{ __('api.ui_shown_once') }}</div>
    </div>
@endif

<div class="panel-modern-card mb-3">
    <div class="card-head">
        <h3><i class="bx bx-plus"></i> {{ __('api.ui_new_token') }}</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route($routePrefix.'.api-tokens.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">{{ __('api.ui_name') }}</label>
                    <input type="text" name="name" class="form-control" required maxlength="100"
                           placeholder="{{ __('api.ui_name_placeholder') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('api.ui_expires') }}</label>
                    <input type="number" name="expires_in_days" class="form-control" min="1" max="3650"
                           placeholder="{{ __('api.ui_never') }}">
                </div>
                <div class="col-md-5">
                    <label class="form-label">{{ __('api.ui_allowed_ips') }}</label>
                    <input type="text" name="allowed_ips" class="form-control" dir="ltr"
                           placeholder="1.2.3.4, 10.0.0.0/24">
                </div>
            </div>

            <div class="mt-3">
                <label class="form-label">{{ __('api.ui_abilities') }}</label>
                <div class="small text-muted mb-2">{{ __('api.ui_abilities_hint') }}</div>
                <div class="row g-2">
                    @foreach ($abilityCatalog as $key => $group)
                        <div class="col-md-4">
                            <div class="border rounded p-2 h-100">
                                <div class="fw-bold small mb-2">{{ $group['label'] }}</div>
                                @foreach ($group['abilities'] as $ability => $label)
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="checkbox"
                                               name="abilities[]" value="{{ $ability }}"
                                               id="ab-{{ \Illuminate\Support\Str::slug($ability) }}">
                                        <label class="form-check-label small"
                                               for="ab-{{ \Illuminate\Support\Str::slug($ability) }}">
                                            {{ $label }}
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <button type="submit" class="btn btn-primary mt-3">
                <i class="bx bx-key"></i> {{ __('api.ui_create') }}
            </button>
        </form>
    </div>
</div>

<div class="panel-modern-card">
    <div class="card-head">
        <h3><i class="bx bx-list-ul"></i> {{ __('api.ui_my_tokens') }}</h3>
    </div>
    <div class="card-body">
        @if ($tokens->isEmpty())
            <div class="alert alert-info mb-0">{{ __('api.ui_no_tokens') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('api.ui_name') }}</th>
                            <th>{{ __('api.ui_abilities') }}</th>
                            <th>{{ __('api.ui_last_used') }}</th>
                            <th>{{ __('api.ui_requests') }}</th>
                            <th>{{ __('api.ui_status') }}</th>
                            <th style="width: 110px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr>
                                <td>{{ $token->name }}</td>
                                <td>
                                    @foreach (\App\Services\ApiTokenService::abilityLabels($token->abilities) as $label)
                                        <span class="badge bg-light text-dark border mb-1">{{ $label }}</span>
                                    @endforeach
                                </td>
                                <td class="small">
                                    {{ $token->last_used_at?->diffForHumans() ?? __('api.ui_never_used') }}
                                </td>
                                <td class="small">{{ persian_digits((string) $token->request_count) }}</td>
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
                                              action="{{ route($routePrefix.'.api-tokens.destroy', $token) }}"
                                              onsubmit="return confirm('{{ __('api.ui_revoke_confirm') }}');">
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
        @endif
    </div>
</div>
@endsection
