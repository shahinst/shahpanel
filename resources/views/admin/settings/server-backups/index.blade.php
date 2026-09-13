@extends('layouts.panel')

@section('page_title', __('server_backups.page_title'))

@section('panel_content')
<p class="mb-3">
    <a href="{{ route('admin.settings.index') }}"><i class="bx bx-arrow-back"></i> {{ __('settings.page_title') }}</a>
</p>

<x-card :title="__('server_backups.page_title')">
    <p class="text-muted small">{{ __('server_backups.intro') }}</p>

    @if (! empty($migrationRequired))
        <div class="alert alert-warning">
            {{ __('server_backups.migration_required') }}
            <div class="mt-2 small text-muted">{{ __('server_backups.migration_hint') }}</div>
        </div>
    @endif

    @if (! empty($loadError))
        <div class="alert alert-danger">{{ __('server_backups.load_failed', ['message' => $loadError]) }}</div>
    @endif

    <div class="panel-modern-card mb-4">
        <div class="card-head"><h3 class="h6 mb-0">{{ __('server_backups.servers_heading') }}</h3></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('server_backups.col_server') }}</th>
                            <th>{{ __('server_backups.col_type') }}</th>
                            <th>{{ __('server_backups.col_host') }}</th>
                            <th>{{ __('server_backups.col_last_backup') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($servers as $server)
                            @php
                                $last = ($recentBackups[(string) $server->id] ?? collect())->first();
                            @endphp
                            <tr>
                                <td>{{ $server->name }}</td>
                                <td><code>{{ $server->type->value }}</code></td>
                                <td class="small">{{ $server->host }}</td>
                                <td class="small">
                                    @if ($last)
                                        <a href="{{ route('admin.settings.server-backups.show', $last) }}">
                                            {{ persian_digits($last->created_at?->format('Y-m-d H:i') ?? '—') }}
                                        </a>
                                        <span class="text-muted"> — {{ $last->status->label() }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('admin.settings.server-backups.store') }}" class="d-inline"
                                          onsubmit="return confirm(@json(__('server_backups.run_confirm', ['name' => $server->name])))">
                                        @csrf
                                        <input type="hidden" name="server_id" value="{{ $server->id }}">
                                        <x-button type="submit" size="sm">
                                            <i class="bx bx-cloud-download"></i> {{ __('server_backups.run_now') }}
                                        </x-button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-muted text-center py-3">{{ __('server_backups.no_servers') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="panel-modern-card">
        <div class="card-head"><h3 class="h6 mb-0">{{ __('server_backups.history_heading') }}</h3></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ __('server_backups.col_server') }}</th>
                            <th>{{ __('server_backups.col_status') }}</th>
                            <th>{{ __('server_backups.col_sections') }}</th>
                            <th>{{ __('server_backups.col_by') }}</th>
                            <th>{{ __('server_backups.col_date') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $flatBackups = $recentBackups->flatten(1)->sortByDesc('id')->take(50);
                        @endphp
                        @forelse ($flatBackups as $backup)
                            <tr @class(['table-danger' => $backup->status->value === 'failed'])>
                                <td>{{ persian_digits($backup->id) }}</td>
                                <td>{{ $backup->server?->name ?? '—' }}</td>
                                <td>{{ $backup->status->label() }}</td>
                                <td>{{ persian_digits((int) (($backup->manifest['format'] ?? '') === 'mikrotik_native'
                                    ? ($backup->manifest['file_count'] ?? 0)
                                    : ($backup->manifest['section_count'] ?? 0))) }}</td>
                                <td class="small">{{ $backup->triggeredBy?->username ?? '—' }}</td>
                                <td class="small">{{ persian_digits($backup->created_at?->format('Y-m-d H:i') ?? '—') }}</td>
                                <td class="text-end">
                                    <a href="{{ route('admin.settings.server-backups.show', $backup) }}" class="btn btn-sm btn-light">
                                        {{ __('server_backups.view') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-muted text-center py-3">{{ __('server_backups.no_backups') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-card>
@endsection
