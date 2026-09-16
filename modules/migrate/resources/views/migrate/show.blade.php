@extends('layouts.panel')

@section('page_title', __('migrate.log_title').' #'.persian_digits($migration->id))

@section('panel_content')
<p><a href="{{ route('admin.migrate.index') }}"><i class="bx bx-arrow-back"></i> {{ __('migrate.page_title') }}</a></p>

<div class="panel-modern-card mb-3">
    <div class="card-head"><h3>{{ __('migrate.log_title') }} #{{ persian_digits($migration->id) }}</h3></div>
    <div class="card-body">
        <dl class="row small mb-0">
            <dt class="col-sm-3 text-muted">{{ __('migrate.from_server') }}</dt>
            <dd class="col-sm-9">{{ $migration->fromServer?->name }}</dd>
            <dt class="col-sm-3 text-muted">{{ __('migrate.to_server') }}</dt>
            <dd class="col-sm-9">{{ $migration->toServer?->name }}</dd>
            <dt class="col-sm-3 text-muted">{{ __('migrate.status') }}</dt>
            <dd class="col-sm-9">{{ $migration->status }} @if($migration->dry_run) ({{ __('ui.migrate_dry_run') }}) @endif</dd>
            <dt class="col-sm-3 text-muted">{{ __('migrate.summary') }}</dt>
            <dd class="col-sm-9">{{ $migration->summary }}</dd>
            <dt class="col-sm-3 text-muted">{{ __('ui.col_time') }}</dt>
            <dd class="col-sm-9">
                {{ $migration->started_at ? persian_digits($migration->started_at->format('Y-m-d H:i')) : '—' }}
                —
                {{ $migration->completed_at ? persian_digits($migration->completed_at->format('Y-m-d H:i')) : '—' }}
            </dd>
            <dt class="col-sm-3 text-muted">{{ __('ui.col_stats') }}</dt>
            <dd class="col-sm-9">
                {{ __('ui.migrate_stat_total') }} {{ persian_digits($migration->total_accounts) }} —
                {{ __('ui.migrate_stat_success') }} {{ persian_digits($migration->migrated_count) }} —
                {{ __('ui.label_error') }} {{ persian_digits($migration->failed_count) }} —
                {{ __('ui.migrate_stat_skipped') }} {{ persian_digits($migration->skipped_count) }}
            </dd>
        </dl>
    </div>
</div>

<div class="panel-modern-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ __('migrate.username') }}</th>
                        <th>{{ __('migrate.status') }}</th>
                        <th>{{ __('migrate.message') }}</th>
                        <th>{{ __('migrate.subscription') }}</th>
                        <th>{{ __('migrate.qr') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($migration->entries as $entry)
                        <tr class="@if($entry->status === 'failed') table-danger @elseif($entry->status === 'success') table-success @endif">
                            <td>{{ persian_digits($entry->account_id ?? '—') }}</td>
                            <td><code>{{ $entry->remote_username }}</code></td>
                            <td>{{ $entry->status }}</td>
                            <td class="small">
                                {{ $entry->message }}
                                @if ($entry->error)
                                    <div class="text-danger">{{ $entry->error }}</div>
                                @endif
                            </td>
                            <td class="small" style="max-width:280px;word-break:break-all">
                                @if ($entry->subscription_url)
                                    <a href="{{ $entry->subscription_url }}" target="_blank" rel="noopener">{{ $entry->subscription_url }}</a>
                                    @if ($entry->account?->portal_token)
                                        <div class="mt-1">
                                            <a href="{{ route('admin.accounts.portal-link', $entry->account) }}" target="_blank">{{ __('menu.portal_link') }}</a>
                                        </div>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if ($entry->subscription_url && Route::has('admin.migrate.entry.qr'))
                                    <a href="{{ route('admin.migrate.entry.qr', $entry) }}" target="_blank" title="QR">
                                        <img src="{{ route('admin.migrate.entry.qr', $entry) }}" alt="QR" width="64" height="64" class="border rounded">
                                    </a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
