@extends('layouts.panel')

@section('page_title', __('updates.title'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('updates.title'),
    'subtitle' => __('updates.version_label', ['version' => $currentVersion]),
    'icon' => 'bx-cloud-download',
    'actions' => '<form method="POST" action="'.route('admin.updates.check').'" class="d-inline">'.csrf_field().'<button type="submit" class="btn btn-light btn-sm"><i class="bx bx-refresh"></i> '.e(__('updates.check_now')).'</button></form>',
])

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="panel-modern-card h-100">
            <div class="card-body">
                <div class="text-muted small">{{ __('updates.installed') }}</div>
                <div class="upd-version" dir="ltr">v{{ $currentVersion }}</div>
                @if ($currentCommit)
                    <div class="text-muted small" dir="ltr">{{ __('updates.commit') }}: {{ $currentCommit }}</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="panel-modern-card h-100">
            <div class="card-body">
                <div class="text-muted small">{{ __('updates.latest') }}</div>
                <div class="upd-version {{ $updateAvailable ? 'text-success' : '' }}" dir="ltr">
                    {{ ($state['ok'] ?? false) ? 'v'.$latestLabel : '—' }}
                </div>
                @if (! empty($state['checked_at']))
                    <div class="text-muted small">{{ __('updates.checked_at', ['time' => jalali_date($state['checked_at'], 'Y/m/d H:i')]) }}</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="panel-modern-card h-100">
            <div class="card-body d-flex align-items-center gap-2">
                @if (! ($state['ok'] ?? false))
                    <i class="bx bx-error-circle text-warning" style="font-size:2rem"></i>
                    <span>{{ __('updates.check_failed', ['error' => $state['error'] ?? '—']) }}</span>
                @elseif ($updateAvailable)
                    <i class="bx bx-gift text-success" style="font-size:2rem"></i>
                    <strong>{{ __('updates.available') }}</strong>
                @else
                    <i class="bx bx-check-shield text-success" style="font-size:2rem"></i>
                    <strong>{{ __('updates.up_to_date') }}</strong>
                @endif
            </div>
        </div>
    </div>
</div>

@if ($updateAvailable)
    <div class="panel-modern-card mb-3">
        <div class="card-head"><h3><i class="bx bx-star"></i> {{ __('updates.whats_new') }}</h3></div>
        <div class="card-body">
            @forelse ($newerNotes as $entry)
                <h4 class="upd-heading" dir="auto">
                    {{ __('updates.version_heading', ['version' => $entry['version']]) }}
                    @if ($entry['date'])
                        <small class="text-muted">— {{ jalali_date($entry['date'], 'Y/m/d') }}</small>
                    @endif
                </h4>
                <ul class="upd-notes">
                    @foreach ($entry['notes'] as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            @empty
                <p class="text-muted mb-0">{{ __('updates.no_notes') }}</p>
            @endforelse

            @if (! empty($state['commits']))
                <details class="mt-3">
                    <summary>{{ __('updates.commits', ['count' => (int) ($state['ahead_by'] ?? count($state['commits']))]) }}</summary>
                    <ul class="upd-commits mt-2" dir="ltr">
                        @foreach ($state['commits'] as $commit)
                            <li>
                                @if (! empty($commit['url']))
                                    <a href="{{ $commit['url'] }}" target="_blank" rel="noopener"><code>{{ $commit['sha'] }}</code></a>
                                @else
                                    <code>{{ $commit['sha'] }}</code>
                                @endif
                                {{ $commit['message'] }}
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>
    </div>
@endif

<div class="panel-modern-card mb-3">
    <div class="card-head"><h3><i class="bx bx-terminal"></i> {{ __('updates.how_title') }}</h3></div>
    <div class="card-body">
        <p>{{ __('updates.how_intro') }}</p>
        <div class="upd-command" dir="ltr">
            <code id="upd-command-text">cd {{ $appDir }} &amp;&amp; sudo bash update.sh</code>
            <button type="button" class="btn btn-sm btn-light" id="upd-copy" data-done="{{ __('updates.copied') }}"><i class="bx bx-copy"></i> {{ __('updates.copy') }}</button>
        </div>
        <p class="mt-3 mb-1"><i class="bx bx-data"></i> {{ __('updates.backup_note', ['dir' => $backupDir, 'name' => basename($backupPath)]) }}</p>
        <p class="mb-0"><i class="bx bx-undo"></i> {{ __('updates.rollback_note') }}</p>
    </div>
</div>

@if (! $updateAvailable && $currentNotes !== [])
    <div class="panel-modern-card mb-3">
        <div class="card-head"><h3>{{ __('updates.current_notes', ['version' => $currentVersion]) }}</h3></div>
        <div class="card-body">
            <ul class="upd-notes mb-0">
                @foreach ($currentNotes as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
@endsection

@push('styles')
<style>
    .upd-version { font-size: 1.6rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .upd-heading { font-size: 1.05rem; font-weight: 700; margin: 0 0 .5rem; }
    .upd-notes { margin: 0 0 1rem; padding-inline-start: 1.2rem; }
    .upd-notes li { margin-bottom: .35rem; }
    .upd-commits { list-style: none; padding: 0; font-size: .85rem; }
    .upd-commits li { margin-bottom: .25rem; }
    .upd-command { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; background: #0d1117; color: #c9d1d9; border-radius: 8px; padding: 10px 14px; }
    .upd-command code { color: #7ee787; background: none; font-size: .95rem; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const btn = document.getElementById('upd-copy');
    if (!btn) return;
    btn.addEventListener('click', function () {
        const text = document.getElementById('upd-command-text').textContent;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () { btn.lastChild.textContent = ' ' + btn.dataset.done; }).catch(function () {});
        }
    });
})();
</script>
@endpush
