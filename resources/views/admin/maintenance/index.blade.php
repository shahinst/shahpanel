@extends('layouts.panel')

@section('page_title', __('maintenance.page_title'))

@section('panel_content')
@if ($missingTables !== [])
    <x-alert type="warning" class="margin-bottom">
        {{ __('maintenance.missing_tables') }}: {{ implode(', ', $missingTables) }}
        — {{ __('maintenance.run_migrate') }}
    </x-alert>
@endif

@if (session('operation_log'))
    <x-card class="margin-bottom" :title="__('maintenance.last_output')">
        <ul class="list-unstyled mb-0">
            @foreach ((array) session('operation_log') as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    </x-card>
@endif

<div class="panel-modern-card mb-4">
    <div class="card-head"><h3>{{ __('maintenance.site_mode') }}</h3></div>
    <div class="card-body">
        <p class="text-muted small">{{ __('maintenance.site_mode_hint') }}</p>

        <form method="POST" action="{{ route('admin.maintenance.settings.update') }}">
            @csrf
            @method('PUT')

            <input type="hidden" name="maintenance_enabled" value="0">
            <div class="form-check mb-3">
                <input type="checkbox" class="form-check-input" id="maintenance_enabled" name="maintenance_enabled" value="1"
                       @checked((bool) old('maintenance_enabled', $maintenanceEnabled))>
                <label class="form-check-label" for="maintenance_enabled">{{ __('maintenance.enable_mode') }}</label>
            </div>

            <div class="mb-3">
                <label class="form-label" for="maintenance_message">{{ __('maintenance.custom_message') }}</label>
                <textarea name="maintenance_message" id="maintenance_message" class="form-control" rows="3">{{ old('maintenance_message', $maintenanceMessage) }}</textarea>
                <p class="text-muted small mt-1 mb-0">{{ __('maintenance.custom_message_hint') }}</p>
            </div>

            <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
        </form>
    </div>
</div>

<div class="panel-modern-card mb-4">
    <div class="card-head d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h3 class="mb-0">{{ __('maintenance.database_backup') }}</h3>
        <form method="POST" action="{{ route('admin.maintenance.database-backup.store') }}">
            @csrf
            <x-button type="submit" size="sm"><i class="bx bx-cloud-download"></i> {{ __('maintenance.create_backup') }}</x-button>
        </form>
    </div>
    <div class="card-body">
        <p class="text-muted small">{{ __('maintenance.database_backup_hint', ['driver' => $databaseDriver]) }}</p>

        @if ($lastBackupAt)
            <p class="small mb-3">{{ __('maintenance.last_backup_at') }}: {{ jalali_date(\Illuminate\Support\Carbon::parse($lastBackupAt)) }}</p>
        @endif

        @if ($backups !== [])
            <div class="table-responsive mb-4">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('maintenance.backup_file') }}</th>
                            <th>{{ __('maintenance.backup_size') }}</th>
                            <th>{{ __('maintenance.ran_at') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($backups as $backup)
                            <tr>
                                <td><code dir="ltr">{{ $backup['filename'] }}</code></td>
                                <td dir="ltr">{{ number_format($backup['size'] / 1024 / 1024, 2) }} MB</td>
                                <td>{{ jalali_date(\Illuminate\Support\Carbon::createFromTimestamp($backup['modified_at'])) }}</td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('admin.maintenance.database-backup.download', ['file' => $backup['filename']]) }}" class="btn btn-sm btn-light">{{ __('maintenance.download') }}</a>
                                    <form method="POST" action="{{ route('admin.maintenance.database-backup.restore') }}" class="d-inline"
                                          onsubmit="return confirm(@json(__('maintenance.restore_confirm')));">
                                        @csrf
                                        <input type="hidden" name="backup_file" value="{{ $backup['filename'] }}">
                                        <input type="hidden" name="confirm_restore" value="1">
                                        <button type="submit" class="btn btn-sm btn-warning">{{ __('maintenance.restore') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.maintenance.database-backup.destroy', $backup['filename']) }}" class="d-inline"
                                          onsubmit="return confirm(@json(__('maintenance.delete_confirm')));">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('app.delete') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-muted">{{ __('maintenance.no_backups') }}</p>
        @endif

        <hr>

        <h4 class="h6">{{ __('maintenance.restore_upload') }}</h4>
        <p class="text-muted small">{{ __('maintenance.restore_upload_hint') }}</p>
        <p class="text-muted small"><code dir="ltr">php artisan backup:restore backup.sql --force</code></p>
        <p class="text-muted small">{{ __('maintenance.restore_large_file_hint') }}</p>
        <form method="POST" action="{{ route('admin.maintenance.database-backup.restore') }}" enctype="multipart/form-data"
              onsubmit="return confirm(@json(__('maintenance.restore_confirm')));">
            @csrf
            <div class="row align-items-end g-3">
                <div class="col-md-6">
                    <input type="file" name="upload_backup" class="form-control" accept=".sql,.sqlite,.db" required>
                </div>
                <div class="col-md-6">
                    <input type="hidden" name="confirm_restore" value="1">
                    <x-button type="submit" variant="warning"><i class="bx bx-upload"></i> {{ __('maintenance.restore_upload_btn') }}</x-button>
                </div>
            </div>
        </form>
    </div>
</div>

<x-card class="margin-bottom" :title="__('maintenance.database')">
    @if ($missingTables !== [])
        <x-alert type="warning" class="margin-bottom">
            {{ __('maintenance.missing_tables') }}: {{ implode(', ', $missingTables) }}
        </x-alert>
    @else
        <x-alert type="success" class="margin-bottom">{{ __('maintenance.schema_ok') }}</x-alert>
    @endif

    <p class="btn-toolbar">
        <form method="POST" action="{{ route('admin.maintenance.migrate') }}" style="display:inline;">
            @csrf
            <x-button type="submit">{{ __('maintenance.run_migrate') }}</x-button>
        </form>
        <form method="POST" action="{{ route('admin.maintenance.verify-schema') }}" style="display:inline;">
            @csrf
            <x-button type="submit" variant="secondary">{{ __('maintenance.verify_schema') }}</x-button>
        </form>
    </p>
    <p class="help-block">{{ __('maintenance.migrate_hint') }}</p>
    @if (Route::has('admin.migrate.index'))
        <p class="mt-3 mb-0">
            <a href="{{ route('admin.migrate.index') }}" class="btn btn-outline-primary btn-sm">
                <i class="bx bx-transfer-alt"></i> {{ __('menu.migrate') }}
            </a>
        </p>
    @endif
</x-card>

@if (Route::has('admin.automation.index'))
    <x-alert type="info" class="margin-bottom">
        {{ __('maintenance.cron_moved') }}
        <a href="{{ route('admin.automation.index') }}">{{ __('menu.automation_cron') }}</a>
    </x-alert>
@endif

<x-card :title="__('maintenance.history')">
    <x-table :headers="[__('maintenance.action'), __('maintenance.status'), __('maintenance.ran_at'), __('maintenance.user')]">
        @forelse ($logs as $log)
            <tr>
                <td>{{ $log->action }}</td>
                <td>{{ $log->status }}</td>
                <td>{{ jalali_date($log->ran_at) }}</td>
                <td>{{ $log->user?->full_name ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted">{{ __('app.no_results') }}</td></tr>
        @endforelse
    </x-table>
</x-card>
@endsection
