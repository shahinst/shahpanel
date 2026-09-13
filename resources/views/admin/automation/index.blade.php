@extends('layouts.panel')

@section('page_title', __('automation.page_title'))

@section('panel_content')
<p class="text-muted">{{ __('automation.page_hint') }}</p>
<p class="margin-bottom">
    <a href="{{ route('admin.automation.pricing') }}" class="btn btn-outline-primary btn-sm">
        <i class="bx bx-purchase-tag"></i> {{ __('menu.automation_pricing') }}
    </a>
</p>

@if (session('success'))
    <x-alert type="success" class="margin-bottom">{{ session('success') }}</x-alert>
@endif
@if (session('warning'))
    <x-alert type="warning" class="margin-bottom">{{ session('warning') }}</x-alert>
@endif

@if (session('install_log'))
    <x-card class="margin-bottom" :title="__('automation.install_log')">
        <ul class="list-unstyled mb-0">
            @foreach ((array) session('install_log') as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    </x-card>
@endif

<div class="row margin-bottom">
    <div class="col-lg-5">
        <x-card :title="__('automation.server_environment')">
            <dl class="row mb-0">
                <dt class="col-sm-4 text-muted">{{ __('automation.env_type') }}</dt>
                <dd class="col-sm-8"><span class="badge bg-primary">{{ $environment['label'] }}</span></dd>
                <dt class="col-sm-4 text-muted">{{ __('automation.project_path') }}</dt>
                <dd class="col-sm-8"><code dir="ltr" class="small">{{ $projectPath }}</code></dd>
                <dt class="col-sm-4 text-muted">{{ __('automation.crontab_status') }}</dt>
                <dd class="col-sm-8">
                    @if ($crontabInstalled)
                        <span class="badge bg-success">{{ __('automation.crontab_installed') }}</span>
                    @else
                        <span class="badge bg-warning text-dark">{{ __('automation.crontab_missing') }}</span>
                    @endif
                </dd>
            </dl>
            <p class="small text-muted mt-3 mb-0">{{ $environment['detail'] }}</p>
        </x-card>
    </div>

    <div class="col-lg-7">
        <x-card :title="__('automation.install_card')">
            <p class="help-block">{{ __('automation.install_hint') }}</p>

            @foreach ($previewLines as $index => $line)
                <div class="mb-3">
                    <label class="form-label small text-muted">{{ __('automation.crontab_line') }} #{{ persian_digits($index + 1) }}</label>
                    <div class="input-group">
                        <code class="form-control small" id="cron-preview-{{ $index }}" style="direction:ltr;text-align:left;">{{ $line }}</code>
                        <button type="button" class="btn btn-outline-secondary btn-sm cron-copy" data-target="cron-preview-{{ $index }}">{{ __('automation.copy') }}</button>
                    </div>
                </div>
            @endforeach

            <div class="btn-toolbar gap-2">
                @if ($canInstall)
                    <form method="POST" action="{{ route('admin.automation.install') }}" onsubmit="return confirm(@json(__('automation.install_confirm')))">
                        @csrf
                        <x-button type="submit">
                            <i class="bx bx-play-circle"></i> {{ __('automation.install_button') }}
                        </x-button>
                    </form>
                @else
                    <x-button type="button" disabled>
                        <i class="bx bx-play-circle"></i> {{ __('automation.install_button') }}
                    </x-button>
                    @if ($installBlockedReason)
                        <span class="text-muted small align-self-center">{{ $installBlockedReason }}</span>
                    @endif
                @endif
            </div>
        </x-card>
    </div>
</div>

<x-card class="margin-bottom" :title="__('automation.monitor_title')">
    <p class="help-block">{{ __('automation.monitor_hint') }}</p>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
            <thead>
                <tr>
                    <th>{{ __('automation.col_status') }}</th>
                    <th>{{ __('automation.col_job') }}</th>
                    <th>{{ __('automation.col_schedule') }}</th>
                    <th>{{ __('automation.col_command') }}</th>
                    <th>{{ __('automation.col_last_run') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cronJobs as $job)
                    @php
                        $status = $job['status'] ?? 'unknown';
                        $badge = match ($status) {
                            'healthy' => 'bg-success',
                            'critical' => 'bg-danger',
                            'warning' => 'bg-warning text-dark',
                            default => 'bg-secondary',
                        };
                    @endphp
                    <tr>
                        <td>
                            <span class="badge {{ $badge }}">{{ $job['status_label'] ?? $status }}</span>
                            @if ($job['required'] ?? false)
                                <span class="badge bg-danger ms-1">{{ __('automation.required') }}</span>
                            @endif
                        </td>
                        <td>
                            <strong>{{ $job['label'] }}</strong>
                            <div class="small text-muted">{{ $job['description'] ?? '' }}</div>
                        </td>
                        <td><code dir="ltr">{{ $job['cron'] ?? '—' }}</code></td>
                        <td><code dir="ltr" class="small">{{ $job['internal'] ?? '—' }}</code></td>
                        <td class="small">
                            @if (! empty($job['last_run_human']))
                                {{ $job['last_run_human'] }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-card>

<x-card :title="__('automation.current_crontab')">
    <pre class="mb-0 small" dir="ltr" style="white-space:pre-wrap;text-align:left;">{{ $currentCrontab }}</pre>
</x-card>

@push('scripts')
<script>
document.querySelectorAll('.cron-copy').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var el = document.getElementById(btn.dataset.target);
        if (!el) return;
        var text = el.textContent.trim();
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
        btn.textContent = @json(__('automation.copied'));
        setTimeout(function () {
            btn.textContent = @json(__('automation.copy'));
        }, 1500);
    });
});
</script>
@endpush
@endsection
