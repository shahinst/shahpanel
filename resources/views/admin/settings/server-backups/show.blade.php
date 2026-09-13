@extends('layouts.panel')

@section('page_title', __('server_backups.show_title', ['name' => $backup->server?->name ?? '—']))

@section('panel_content')
<p class="mb-3">
    <a href="{{ route('admin.settings.server-backups.index') }}"><i class="bx bx-arrow-back"></i> {{ __('server_backups.page_title') }}</a>
</p>

<x-card :title="__('server_backups.show_title', ['name' => $backup->server?->name ?? '—'])">
    <p class="text-muted small mb-3">{{ __('server_backups.view_only_hint') }}</p>

    <dl class="row small mb-4">
        <dt class="col-sm-3 text-muted">{{ __('server_backups.col_status') }}</dt>
        <dd class="col-sm-9">{{ $backup->status->label() }}</dd>
        <dt class="col-sm-3 text-muted">{{ __('server_backups.col_date') }}</dt>
        <dd class="col-sm-9">{{ persian_digits($backup->created_at?->format('Y-m-d H:i:s') ?? '—') }}</dd>
        <dt class="col-sm-3 text-muted">{{ __('server_backups.col_by') }}</dt>
        <dd class="col-sm-9">{{ $backup->triggeredBy?->full_name ?? $backup->triggeredBy?->username ?? '—' }}</dd>
        <dt class="col-sm-3 text-muted">{{ __('server_backups.col_type') }}</dt>
        <dd class="col-sm-9"><code>{{ $backup->server?->type->value ?? ($metadata['server_type'] ?? '—') }}</code></dd>
        <dt class="col-sm-3 text-muted">{{ __('server_backups.storage_folder') }}</dt>
        <dd class="col-sm-9"><code dir="ltr">{{ $backup->storage_dir }}</code></dd>
        <dt class="col-sm-3 text-muted">{{ __('server_backups.storage_path') }}</dt>
        <dd class="col-sm-9"><code dir="ltr" class="small">{{ $backup->absoluteStoragePath() }}</code></dd>
    </dl>

    @if ($backup->error)
        <div class="alert alert-warning small">{{ nl2br(e($backup->error)) }}</div>
    @endif

    @if (($manifest['format'] ?? '') === 'mikrotik_native' && $backupFiles !== [])
        <p class="text-muted small mb-3">{{ __('server_backups.mikrotik_native_hint') }}</p>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>{{ __('server_backups.col_file') }}</th>
                        <th>{{ __('server_backups.col_size') }}</th>
                        <th>{{ __('server_backups.col_format') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($backupFiles as $fileKey => $fileInfo)
                        <tr>
                            <td><code dir="ltr">{{ $fileInfo['label'] ?? $fileInfo['file'] ?? $fileKey }}</code></td>
                            <td>{{ persian_digits(format_data_size((int) ($fileInfo['bytes'] ?? 0))) }}</td>
                            <td><code>.backup</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif ($sections === [])
        <p class="text-muted">{{ __('server_backups.no_sections') }}</p>
    @else
        <ul class="nav nav-tabs mb-3 flex-wrap">
            @foreach ($sections as $section)
                <li class="nav-item">
                    <a href="{{ route('admin.settings.server-backups.show', ['serverBackup' => $backup->id, 'section' => $section]) }}"
                       @class(['nav-link', 'active' => $activeSection === $section])>
                        {{ $section }}
                        @php $info = $manifest['sections'][$section] ?? null; @endphp
                        @if (is_array($info) && isset($info['count']))
                            <span class="badge bg-secondary">{{ persian_digits((int) $info['count']) }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($activeSection !== null)
            <h6 class="mb-2">{{ $activeSection }}</h6>
            <pre class="bg-dark text-light p-3 rounded small mb-0" style="max-height:70vh;overflow:auto;white-space:pre-wrap;word-break:break-word;direction:ltr;text-align:left;">{{ $sectionPayload !== null ? e(json_encode($sectionPayload['data'] ?? $sectionPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : __('server_backups.section_unreadable') }}</pre>
        @endif
    @endif
</x-card>
@endsection
