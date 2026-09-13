@if (! $queueHealth['healthy'] || $queueHealth['pending'] > 0)
<div class="panel-modern-card mb-3 border-warning">
    <div class="card-head"><h3 class="text-warning mb-0"><i class="bx bx-time-five"></i> {{ __('tunneling.queue_status') }}</h3></div>
    <div class="card-body">
        @if (! $queueHealth['healthy'])
            <x-alert type="warning" class="mb-3">
                {{ __('tunneling.queue_worker_inactive') }}
                @if ($queueHealth['sync_fallback'])
                    <br><strong>{{ __('tunneling.queue_sync_fallback') }}</strong>
                @endif
            </x-alert>
        @endif

        <ul class="mb-3 small">
            <li>{{ __('tunneling.queue_pending') }}: <strong>{{ persian_digits($queueHealth['pending']) }}</strong></li>
            <li>{{ __('tunneling.queue_failed') }}: <strong>{{ persian_digits($queueHealth['failed']) }}</strong></li>
            <li>{{ __('tunneling.queue_last_run') }}: <strong>{{ $queueHealth['last_run'] ?? '—' }}</strong></li>
        </ul>

        <p class="mb-2 small text-muted">{{ __('tunneling.queue_cron_hint') }}</p>
        <pre class="bg-light p-3 rounded small mb-0" dir="ltr">* * * * * cd /path/to/panel && php artisan schedule:run</pre>
        <p class="mt-2 mb-0 small">{{ __('tunneling.queue_manual_hint') }} <code dir="ltr">php artisan queue:work --queue=tunneling,default --stop-when-empty --max-time=55</code></p>
    </div>
</div>
@endif
