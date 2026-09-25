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
        <div class="card-head"><h3 class="h6 mb-0">{{ __('server_backups.telegram_heading') }}</h3></div>
        <div class="card-body">
            <p class="text-muted small">{{ __('server_backups.telegram_intro') }}</p>

            @php
                $tokenHint = __('server_backups.telegram_bot_token_hint');

                if (($telegramConfigured ?? false) && ($telegramMaskedToken ?? null) !== null) {
                    $tokenHint = __('server_backups.telegram_bot_token_current', ['token' => $telegramMaskedToken]).' — '.$tokenHint;
                }
            @endphp

            <form method="POST" action="{{ route('admin.settings.server-backups.telegram') }}">
                @csrf
                <div class="row">
                    <x-form.group :label="__('server_backups.telegram_bot_token')" :hint="$tokenHint">
                        <input type="text" name="telegram_bot_token" class="form-control" dir="ltr"
                               autocomplete="off" spellcheck="false" value="" placeholder="123456789:AA...">
                    </x-form.group>

                    <x-form.group :label="__('server_backups.telegram_chat_id')"
                                  :hint="__('server_backups.telegram_chat_id_hint')">
                        <input type="text" name="telegram_chat_id" class="form-control" dir="ltr"
                               autocomplete="off" spellcheck="false"
                               value="{{ old('telegram_chat_id', $telegramChatId ?? '') }}" placeholder="123456789">
                    </x-form.group>

                    <x-form.checkbox name="telegram_enabled"
                                     :label="__('server_backups.telegram_enabled')"
                                     :checked="old('telegram_enabled', $telegramEnabled ?? false)"
                                     :hint="__('server_backups.telegram_enabled_hint')"
                                     :hiddenZero="true" />
                </div>

                <x-button type="submit"><i class="bx bx-save"></i> {{ __('server_backups.telegram_save') }}</x-button>
            </form>

            @if ($telegramConfigured ?? false)
                <form method="POST" action="{{ route('admin.settings.server-backups.telegram-test') }}" class="mt-3">
                    @csrf
                    <x-button type="submit" variant="ghost" size="sm">
                        <i class="bx bx-send"></i> {{ __('server_backups.telegram_test_send') }}
                    </x-button>
                </form>
            @else
                <p class="text-muted small mt-3 mb-0">
                    <i class="bx bx-info-circle align-middle"></i>
                    {{ __('server_backups.telegram_not_configured') }}
                </p>
            @endif
        </div>
    </div>

    <div class="panel-modern-card mb-4">
        <div class="card-head"><h3 class="h6 mb-0">{{ __('server_backups.schedule_heading') }}</h3></div>
        <div class="card-body">
            <p class="text-muted small mb-1">{{ __('server_backups.schedule_intro') }}</p>
            <p class="text-muted small">
                <i class="bx bx-time-five align-middle"></i>
                {{ __('server_backups.schedule_timezone_hint', ['timezone' => $panelTimezone ?? config('app.timezone')]) }}
            </p>

            @if (empty($scheduleReady))
                <div class="alert alert-warning mb-0">
                    {{ __('server_backups.migration_required') }}
                    <div class="mt-2 small text-muted">{{ __('server_backups.migration_hint') }}</div>
                </div>
            @else
                <form method="POST" action="{{ route('admin.settings.server-backups.schedule') }}">
                    @csrf
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-2">
                            <thead>
                                <tr>
                                    <th>{{ __('server_backups.col_server') }}</th>
                                    <th>{{ __('server_backups.schedule_enabled') }}</th>
                                    <th>{{ __('server_backups.schedule_times') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($servers as $server)
                                    @php
                                        $serverTimes = implode(', ', $server->backupTimes());
                                    @endphp
                                    <tr>
                                        <td>
                                            {{ $server->name }}
                                            <div class="text-muted small"><code>{{ $server->type->value }}</code></div>
                                        </td>
                                        <td>
                                            <div class="form-check mb-0">
                                                <input type="hidden" name="schedule_enabled[{{ $server->id }}]" value="0">
                                                <input type="checkbox" class="form-check-input"
                                                       id="schedule_enabled_{{ $server->id }}"
                                                       name="schedule_enabled[{{ $server->id }}]" value="1"
                                                       @checked(old('schedule_enabled.'.$server->id, $server->backup_schedule_enabled))>
                                                <label class="form-check-label small" for="schedule_enabled_{{ $server->id }}">
                                                    {{ __('app.active') }}
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm" dir="ltr"
                                                   name="schedule_times[{{ $server->id }}]"
                                                   value="{{ old('schedule_times.'.$server->id, $serverTimes) }}"
                                                   placeholder="{{ __('server_backups.schedule_times_placeholder') }}">
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-muted text-center py-3">{{ __('server_backups.no_servers') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <small class="text-muted d-block mb-3">{{ __('server_backups.schedule_times_hint') }}</small>

                    @if ($servers->isNotEmpty())
                        <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
                    @endif
                </form>
            @endif
        </div>
    </div>

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
                            <th>{{ __('server_backups.col_schedule') }}</th>
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
                                <td class="small" dir="ltr">
                                    @php
                                        $rowTimes = $server->backupTimes();
                                    @endphp
                                    @if ($server->backup_schedule_enabled && $rowTimes !== [])
                                        {{ persian_digits(implode(' ، ', $rowTimes)) }}
                                    @else
                                        <span class="text-muted">{{ __('server_backups.schedule_none') }}</span>
                                    @endif
                                </td>
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
                                <td colspan="6" class="text-muted text-center py-3">{{ __('server_backups.no_servers') }}</td>
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
