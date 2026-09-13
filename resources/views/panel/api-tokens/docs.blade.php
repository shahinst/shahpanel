@extends('layouts.panel')

@section('page_title', __('api.docs_title'))

@section('panel_content')
<div class="panel-modern-card mb-3">
    <div class="card-head d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h3 class="mb-0"><i class="bx bx-book-open"></i> {{ __('api.docs_title') }}</h3>
        <a href="{{ route($routePrefix.'.api-tokens.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bx bx-key"></i> {{ __('api.docs_back_to_tokens') }}
        </a>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">{{ __('api.docs_intro') }}</p>
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

<div class="panel-modern-card mb-3">
    <div class="card-head">
        <h3><i class="bx bx-code-block"></i> {{ __('api.docs_envelope_title') }}</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small">{{ __('api.docs_envelope_note') }}</p>
        <pre class="bg-dark text-light p-3 rounded small mb-0" dir="ltr"><code>{{ '{ "ok": true,  "data": { … }, "meta": { "pagination": { … } } }' }}
{{ '{ "ok": false, "error": { "code": "…", "message": "…" } }' }}</code></pre>
    </div>
</div>

@foreach ($groups as $group)
    <div class="panel-modern-card mb-3">
        <div class="card-head">
            <h3><i class="bx bx-chevron-left-circle"></i> {{ $group['title'] }}</h3>
        </div>
        <div class="card-body">
            @if ($group['intro'])
                <p class="text-muted small">{{ $group['intro'] }}</p>
            @endif

            @foreach ($group['endpoints'] as $ep)
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                        @php
                            $badge = $ep['method'] === 'GET' ? 'bg-success' : 'bg-primary';
                        @endphp
                        <span class="badge {{ $badge }}" dir="ltr">{{ $ep['method'] }}</span>
                        <code dir="ltr" class="fs-6">{{ $ep['path'] }}</code>
                    </div>

                    <div class="mb-2">{{ $ep['summary'] }}</div>

                    <div class="d-flex flex-wrap gap-2 mb-3">
                        @if ($ep['ability'])
                            <span class="badge bg-secondary">
                                {{ __('api.docs_needs_ability') }}: {{ $abilityLabels[$ep['ability']] ?? $ep['ability'] }}
                            </span>
                        @endif
                        @if ($ep['role'] === 'agent')
                            <span class="badge bg-warning text-dark">{{ __('api.docs_agent_only') }}</span>
                        @endif
                    </div>

                    <div class="fw-bold small mb-1">{{ __('api.docs_params') }}</div>
                    @if ($ep['params'] === [])
                        <div class="text-muted small mb-2">{{ __('api.docs_no_params') }}</div>
                    @else
                        <div class="table-responsive mb-2">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 190px;">{{ __('api.docs_param') }}</th>
                                        <th style="width: 90px;">{{ __('api.docs_type') }}</th>
                                        <th style="width: 90px;">{{ __('api.docs_required') }}</th>
                                        <th>{{ __('api.docs_description') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($ep['params'] as $p)
                                        <tr>
                                            <td><code dir="ltr">{{ $p['name'] }}</code></td>
                                            <td class="small" dir="ltr">{{ $p['type'] }}</td>
                                            <td class="small">
                                                @if ($p['required'])
                                                    <span class="text-danger">{{ __('api.docs_required') }}</span>
                                                @else
                                                    <span class="text-muted">{{ __('api.docs_optional') }}</span>
                                                @endif
                                            </td>
                                            <td class="small">{{ $p['note'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="small">
                        <span class="fw-bold">{{ __('api.docs_returns') }}:</span>
                        <span class="text-muted">{{ $ep['returns'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endforeach

<div class="panel-modern-card mb-3">
    <div class="card-head">
        <h3><i class="bx bx-error-circle"></i> {{ __('api.docs_errors_title') }}</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 80px;">HTTP</th>
                        <th style="width: 200px;">{{ __('api.docs_error_code') }}</th>
                        <th>{{ __('api.docs_error_meaning') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($errors_list as $e)
                        <tr>
                            <td dir="ltr">{{ $e['status'] }}</td>
                            <td><code dir="ltr">{{ $e['code'] }}</code></td>
                            <td class="small">{{ $e['meaning'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="panel-modern-card">
    <div class="card-head">
        <h3><i class="bx bx-tachometer"></i> {{ __('api.docs_rate_title') }}</h3>
    </div>
    <div class="card-body">
        <p class="mb-0 small text-muted">{{ __('api.docs_rate_note') }}</p>
    </div>
</div>
@endsection
