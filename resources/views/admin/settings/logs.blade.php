@extends('layouts.panel')

@section('page_title', __('settings.logs_title'))

@section('panel_content')
<p class="mb-3">
    <a href="{{ route('admin.settings.index') }}"><i class="bx bx-arrow-back"></i> {{ __('settings.page_title') }}</a>
</p>

<x-card :title="__('settings.logs_title')">
    @if (! empty($loadError))
        <div class="alert alert-danger">{{ __('settings.logs_load_failed') }}: {{ $loadError }}</div>
    @endif

    <form method="GET" action="{{ route('admin.settings.logs') }}" class="row g-3 align-items-end mb-3">
        <div class="col-md-3">
            <label class="form-label" for="log-file">{{ __('settings.logs_file') }}</label>
            <select name="file" id="log-file" class="form-control">
                @forelse ($files as $file)
                    @php
                        $fileLabel = $file['name'].' ('.number_format($file['size'] / 1024, 1).' KB';
                        if (empty($file['writable'])) {
                            $fileLabel .= ' — '.__('settings.logs_readonly_badge');
                        }
                        $fileLabel .= ')';
                    @endphp
                    <option value="{{ $file['name'] }}" @selected($selectedFile === $file['name'])>{{ $fileLabel }}</option>
                @empty
                    <option value="laravel.log">laravel.log</option>
                @endforelse
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="log-level">{{ __('settings.logs_level') }}</label>
            <select name="level" id="log-level" class="form-control">
                @foreach (['all', 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $levelOption)
                    <option value="{{ $levelOption }}" @selected($level === $levelOption)>
                        {{ __('settings.logs_levels.'.$levelOption) }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="log-lines">{{ __('settings.logs_lines') }}</label>
            <input type="number" name="lines" id="log-lines" class="form-control" min="50" max="2000" step="50" value="{{ $lines }}">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="log-search">{{ __('settings.logs_search') }}</label>
            <input type="search" name="q" id="log-search" class="form-control" value="{{ $search }}" placeholder="{{ __('settings.logs_search_placeholder') }}">
        </div>
        <div class="col-md-2 d-flex gap-2">
            <x-button type="submit">{{ __('settings.logs_refresh') }}</x-button>
        </div>
    </form>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <p class="text-muted small mb-0">
            @if ($logMissing)
                {{ __('settings.logs_file_missing') }}
            @elseif (! empty($logNotReadable))
                {{ __('settings.logs_file_not_readable', ['file' => $selectedFile]) }}
            @else
                {{ __('settings.logs_showing', ['count' => persian_digits($logLineCount)]) }}
                @if ($logTruncated)
                    — {{ __('settings.logs_truncated') }}
                @endif
            @endif
        </p>
        @if (! $logMissing && empty($logNotReadable) && $selectedFile !== '' && ($canClearSelectedFile ?? false))
            <form method="POST" action="{{ route('admin.settings.logs.clear') }}" class="d-inline"
                  onsubmit="return confirm(@json(__('settings.logs_clear_confirm')))">
                @csrf
                <input type="hidden" name="file" value="{{ $selectedFile }}">
                <x-button type="submit" variant="danger" size="sm">{{ __('settings.logs_clear') }}</x-button>
            </form>
        @elseif (! $logMissing && $selectedFile !== '' && ! ($canClearSelectedFile ?? false))
            <span class="text-muted small">{{ __('settings.logs_clear_not_writable', ['file' => $selectedFile]) }}</span>
        @endif
    </div>

    <pre class="bg-dark text-light p-3 rounded small mb-0" style="max-height:70vh;overflow:auto;white-space:pre-wrap;word-break:break-word;direction:ltr;text-align:left;">{{ $logContent !== '' ? e($logContent) : __('settings.logs_empty') }}</pre>
</x-card>
@endsection
