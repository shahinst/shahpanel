@extends('layouts.panel')

@section('page_title', __('migrate.page_title'))

@section('panel_content')
@if (empty($migrationTablesReady))
    <x-alert type="warning" class="margin-bottom">
        {{ __('ui.migrate_tables_missing_before') }}
        <a href="{{ route('admin.maintenance.index') }}">{{ __('ui.db_maintenance_link') }}</a>
        {{ __('ui.migrate_tables_missing_after') }} <code>php artisan migrate</code>
    </x-alert>
@endif

<p class="text-muted">{{ __('migrate.page_hint') }}</p>

<div class="panel-modern-card mb-3">
    <div class="card-head"><h3>{{ __('migrate.page_title') }}</h3></div>
    <div class="card-body">
        <form method="GET" action="{{ route('admin.migrate.index') }}" class="row g-3 align-items-end mb-4">
            <div class="col-md-4">
                <label class="form-label">{{ __('migrate.from_server') }}</label>
                <select name="from_server_id" class="form-select" required onchange="this.form.submit()">
                    <option value="">{{ __('migrate.choose_from') }}</option>
                    @foreach ($sanaeiServers as $server)
                        <option value="{{ $server->id }}" @selected($fromServer?->id === $server->id)>{{ $server->name }} ({{ $server->host }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <x-button type="submit" variant="secondary">{{ __('migrate.load_accounts') }}</x-button>
            </div>
        </form>

        @if ($fromServer)
            <form method="POST" action="{{ route('admin.migrate.store') }}" id="migrate-form">
                @csrf
                <input type="hidden" name="from_server_id" value="{{ $fromServer->id }}">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('migrate.to_server') }}</label>
                        <select name="to_server_id" class="form-select" required>
                            <option value="">{{ __('migrate.choose_to') }}</option>
                            @foreach ($remnawaveServers as $server)
                                <option value="{{ $server->id }}" @disabled(! $server->hasStoredRemnawaveApiToken())>
                                    {{ $server->name }} ({{ $server->host }})@if(! $server->hasStoredRemnawaveApiToken()) — {{ __('migrate.remnawave_token_required') }}@endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('migrate.target_package') }}</label>
                        <select name="target_package_id" class="form-select">
                            <option value="">{{ __('migrate.target_package_auto') }}</option>
                            @foreach ($remnawavePackages as $package)
                                <option value="{{ $package->id }}">{{ $package->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-muted small mb-0 mt-1">{{ __('migrate.target_package_hint') }}</p>
                    </div>
                    <div class="col-md-6 d-flex flex-column gap-2 justify-content-end">
                        <x-form.checkbox name="migrate_all" :label="__('migrate.migrate_all')" :checked="false" />
                        <x-form.checkbox name="try_disable_source" :label="__('migrate.try_disable_source')" />
                    </div>
                </div>

                <h4 class="h6">{{ __('migrate.accounts_on_server') }} ({{ persian_digits($accounts->count()) }})</h4>

                @if ($accounts->isEmpty())
                    <p class="text-muted">{{ __('migrate.no_accounts') }}</p>
                @else
                    <div class="table-responsive mb-3" style="max-height:420px;overflow:auto">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th style="width:40px"><input type="checkbox" id="select-all-accounts" title="{{ __('migrate.select_all') }}"></th>
                                    <th>#</th>
                                    <th>{{ __('migrate.username') }}</th>
                                    <th>{{ __('migrate.usage') }}</th>
                                    <th>{{ __('migrate.expiry') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($accounts as $account)
                                    @php
                                        $usedGb = round($account->data_used_bytes / (1024 ** 3), 2);
                                        $limitGb = $account->isUnlimited() ? '∞' : round((int) $account->data_limit_bytes / (1024 ** 3), 2);
                                    @endphp
                                    <tr>
                                        <td><input type="checkbox" name="account_ids[]" value="{{ $account->id }}" class="account-pick"></td>
                                        <td>{{ persian_digits($account->id) }}</td>
                                        <td><code>{{ $account->remote_username }}</code></td>
                                        <td>{{ persian_digits($usedGb) }} / {{ is_numeric($limitGb) ? persian_digits($limitGb) : $limitGb }} GB</td>
                                        <td>{{ $account->expiry_at ? persian_digits($account->expiry_at->format('Y-m-d')) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <input type="hidden" name="dry_run" id="migrate-dry-run" value="0">
                    <div class="d-flex flex-wrap gap-2">
                        <x-button type="submit" onclick="document.getElementById('migrate-dry-run').value='0'; return confirm(@json(__('app.confirm')))">
                            <i class="bx bx-transfer"></i> {{ __('migrate.migrate_selected') }}
                        </x-button>
                        <x-button type="submit" variant="secondary" onclick="document.getElementById('migrate-dry-run').value='1'">
                            <i class="bx bx-show"></i> {{ __('migrate.preview_selected') }}
                        </x-button>
                    </div>
                @endif
            </form>
        @endif
    </div>
</div>

@if ($recentMigrations->isNotEmpty())
<div class="panel-modern-card">
    <div class="card-head"><h3>{{ __('migrate.recent_migrations') }}</h3></div>
    <div class="card-body">
        <x-table :headers="['#', __('migrate.from_server'), __('migrate.to_server'), __('migrate.status'), __('migrate.summary'), '']">
            @foreach ($recentMigrations as $log)
                <tr>
                    <td>{{ persian_digits($log->id) }}</td>
                    <td>{{ $log->fromServer?->name }}</td>
                    <td>{{ $log->toServer?->name }}</td>
                    <td><span class="badge bg-{{ $log->status === 'completed' ? 'success' : ($log->status === 'failed' ? 'danger' : 'secondary') }}">{{ $log->status }}</span></td>
                    <td class="small">{{ $log->summary }}</td>
                    <td><a href="{{ route('admin.migrate.show', $log) }}">{{ __('migrate.view_log') }}</a></td>
                </tr>
            @endforeach
        </x-table>
    </div>
</div>
@endif

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('migrate-form');
    var selectAll = document.getElementById('select-all-accounts');
    var migrateAll = document.getElementById('chk_migrate_all');
    var picks = document.querySelectorAll('.account-pick');

    function setAllPicks(checked) {
        picks.forEach(function (cb) { cb.checked = checked; });
        if (selectAll) selectAll.checked = checked;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            setAllPicks(selectAll.checked);
        });
    }

    if (migrateAll) {
        migrateAll.addEventListener('change', function () {
            if (migrateAll.checked) {
                setAllPicks(true);
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function () {
            if (migrateAll && migrateAll.checked) {
                picks.forEach(function (cb) { cb.checked = true; });
            }
        });
    }
});
</script>
@endpush
@endsection
