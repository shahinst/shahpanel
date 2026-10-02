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

@if ($webUpdaterReady)
<div class="panel-modern-card mb-3" id="upd-run-card">
    <div class="card-head d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h3 class="mb-0"><i class="bx bx-cloud-download"></i> {{ __('updates.run_title') }}</h3>
        <span class="badge" id="upd-run-status" hidden></span>
    </div>
    <div class="card-body">
        <p class="mb-2">{{ __('updates.run_intro') }}</p>
        <p class="mb-3 text-muted small"><i class="bx bx-data"></i> {{ __('updates.backup_note', ['dir' => $backupDir, 'name' => basename($backupPath)]) }}</p>
        <button type="button" class="btn btn-primary" id="upd-run-btn">
            <i class="bx bx-rocket"></i> {{ $updateAvailable ? __('updates.run_button') : __('updates.run_button_reinstall') }}
        </button>

        <div id="upd-run-area" hidden>
            <div class="op-progress mt-3" id="upd-bar"><div class="op-progress__fill" id="upd-fill"></div><span class="op-progress__label" id="upd-pct">0%</span></div>
            <div class="small mt-2" id="upd-stage"></div>
            <div class="alert mt-2 mb-2" id="upd-result" hidden></div>
            <div class="op-console mt-2" id="upd-log" dir="ltr"></div>
            <button type="button" class="btn btn-success mt-3" id="upd-reload" hidden><i class="bx bx-refresh"></i> {{ __('updates.reload') }}</button>
        </div>
    </div>
</div>

<div class="upd-modal" id="upd-confirm" hidden role="dialog" aria-modal="true" aria-labelledby="upd-confirm-title">
    <div class="upd-modal__card">
        <h4 id="upd-confirm-title"><i class="bx bx-shield-quarter"></i> {{ __('updates.confirm_title') }}</h4>
        <p>{{ __('updates.confirm_text') }}</p>
        <div class="upd-modal__backup">
            <div><span class="text-muted">{{ __('updates.confirm_backup_dir') }}</span> <code dir="ltr">{{ $backupDir }}</code></div>
            <div><span class="text-muted">{{ __('updates.confirm_backup_name') }}</span> <code dir="ltr">{{ basename($backupPath) }}</code></div>
        </div>
        <p class="small text-muted mb-3">{{ __('updates.rollback_note') }}</p>
        <div class="d-flex gap-2 justify-content-end">
            <button type="button" class="btn btn-light" id="upd-confirm-no">{{ __('updates.confirm_no') }}</button>
            <button type="button" class="btn btn-primary" id="upd-confirm-yes">{{ __('updates.confirm_yes') }}</button>
        </div>
    </div>
</div>
@endif

<div class="panel-modern-card mb-3">
    <div class="card-head"><h3><i class="bx bx-terminal"></i> {{ $webUpdaterReady ? __('updates.how_title_manual') : __('updates.how_title') }}</h3></div>
    <div class="card-body">
        <p>{{ __('updates.how_intro') }}</p>
        <div class="upd-command" dir="ltr">
            <code id="upd-command-text">cd {{ $appDir }} &amp;&amp; sudo bash update.sh</code>
            <button type="button" class="btn btn-sm btn-light" id="upd-copy" data-done="{{ __('updates.copied') }}"><i class="bx bx-copy"></i> {{ __('updates.copy') }}</button>
        </div>
        <p class="mt-3 mb-1"><i class="bx bx-data"></i> {{ __('updates.backup_note', ['dir' => $backupDir, 'name' => basename($backupPath)]) }}</p>
        <p class="mb-0"><i class="bx bx-undo"></i> {{ __('updates.rollback_note') }}</p>
        @unless ($webUpdaterReady)
            <p class="mt-2 mb-0 text-primary"><i class="bx bx-info-circle"></i> {{ __('updates.web_activate_hint') }}</p>
        @endunless
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
    .op-progress { position: relative; height: 26px; border-radius: 6px; background: #e9ecef; overflow: hidden; }
    .op-progress__fill { position: absolute; inset: 0 auto 0 0; width: 0; background: linear-gradient(90deg, #1668dc, #3b8cff); transition: width .4s ease; }
    .op-progress--done .op-progress__fill { background: linear-gradient(90deg, #198754, #2fb380); }
    .op-progress--error .op-progress__fill { background: linear-gradient(90deg, #b02a37, #dc3545); }
    .op-progress__label { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #111; direction: ltr; }
    .op-console { background: #0d1117; color: #c9d1d9; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; line-height: 1.55; height: 380px; overflow: auto; border-radius: 6px; padding: 10px 12px; white-space: pre-wrap; word-break: break-word; }
    .upd-modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(8, 12, 28, .55); }
    .upd-modal[hidden] { display: none; }
    .upd-modal__card { width: min(480px, 100%); background: #fff; color: #111; border-radius: 16px; padding: 22px; box-shadow: 0 24px 60px rgba(0,0,0,.3); }
    .upd-modal__card h4 { font-size: 1.15rem; font-weight: 800; margin-bottom: 10px; }
    .upd-modal__backup { background: #f4f6fb; border-radius: 10px; padding: 10px 12px; margin-bottom: 12px; display: grid; gap: 4px; }
    .upd-modal__backup code { word-break: break-all; }
</style>
@endpush

@push('scripts')
@if ($webUpdaterReady)
<script>
(function () {
    const runUrl = @json(route('admin.updates.run'));
    const stamp = @json($stamp);
    const csrf = document.querySelector('meta[name="csrf-token"]');
    const labels = {
        stages: @json(__('updates.stages')),
        status: @json(__('updates.statuses')),
        success: @json(__('updates.result_success')),
        rolledBack: @json(__('updates.result_rolled_back')),
        rollbackFailed: @json(__('updates.result_rollback_failed')),
        failed: @json(__('updates.result_failed')),
        lost: @json(__('updates.result_lost')),
        logUnavailable: @json(__('updates.log_unavailable')),
    };
    const stagePercent = { queued: 2, start: 3, checking: 6, backup: 15, fetch: 28, merge: 38, composer: 58, migrate: 74, caches: 84, verify: 91, restart: 96, done: 100 };
    const btn = document.getElementById('upd-run-btn');
    const modal = document.getElementById('upd-confirm');
    const area = document.getElementById('upd-run-area');
    const logEl = document.getElementById('upd-log');
    const fill = document.getElementById('upd-fill');
    const pct = document.getElementById('upd-pct');
    const bar = document.getElementById('upd-bar');
    const stageEl = document.getElementById('upd-stage');
    const result = document.getElementById('upd-result');
    const statusBadge = document.getElementById('upd-run-status');
    const reloadBtn = document.getElementById('upd-reload');
    let logOffset = 0;
    let logUrl = null;
    let statusUrl = null;
    let polls = 0;
    let lastChange = Date.now();
    let bestPercent = 0;
    let logWarned = false;

    function openModal() { modal.hidden = false; }
    function closeModal() { modal.hidden = true; }
    btn.addEventListener('click', openModal);
    document.getElementById('upd-confirm-no').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    function setPercent(p) {
        bestPercent = Math.max(bestPercent, p);
        fill.style.width = bestPercent + '%';
        pct.textContent = bestPercent + '%';
    }

    function append(text) {
        if (!text) return;
        // Plain output is asked for, but strip any colour codes that slip through.
        logEl.appendChild(document.createTextNode(text.replace(/\x1b\[[0-9;]*m/g, '')));
        logEl.scrollTop = logEl.scrollHeight;
    }

    function showResult(kind, message) {
        result.hidden = false;
        result.className = 'alert mt-2 mb-2 alert-' + kind;
        result.textContent = message;
    }

    function readLog() {
        return fetch(logUrl + '?t=' + Date.now(), { headers: { Range: 'bytes=' + logOffset + '-' }, cache: 'no-store' })
            .then(function (r) {
                if (r.status === 416) return '';
                if (!r.ok) {
                    // Say why the console is empty (a firewall refusing the
                    // file, say) instead of leaving a blank box.
                    if (!logWarned) {
                        logWarned = true;
                        append(labels.logUnavailable.replace(':status', r.status) + '\n');
                    }
                    throw new Error(r.status);
                }
                return r.text().then(function (t) {
                    // A server that ignores Range sends the whole file.
                    if (r.status === 200 && logOffset > 0) t = t.slice(logOffset);
                    return t;
                });
            })
            .then(function (t) {
                if (t) { logOffset += new TextEncoder().encode(t).length; append(t); lastChange = Date.now(); }
            });
    }

    function readStatus() {
        return fetch(statusUrl + '?t=' + Date.now(), { cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; });
    }

    function finish(st) {
        bar.classList.toggle('op-progress--done', st.status === 'success');
        bar.classList.toggle('op-progress--error', st.status !== 'success');
        statusBadge.hidden = false;
        statusBadge.className = 'badge ' + (st.status === 'success' ? 'bg-success' : st.status === 'rolled_back' ? 'bg-warning text-dark' : 'bg-danger');
        statusBadge.textContent = labels.status[st.status] || st.status;
        if (st.status === 'success') {
            setPercent(100);
            showResult('success', labels.success.replace(':from', st.from || '').replace(':to', st.to || ''));
        } else if (st.status === 'rolled_back') {
            showResult('warning', labels.rolledBack.replace(':error', st.error || '—').replace(':backup', st.backup || '—'));
        } else if (st.status === 'rollback_failed') {
            showResult('danger', labels.rollbackFailed.replace(':error', st.error || '—').replace(':backup', st.backup || '—'));
        } else {
            showResult('danger', labels.failed.replace(':error', st.error || '—'));
        }
        reloadBtn.hidden = false;
    }

    function poll() {
        polls++;
        Promise.all([readLog().catch(function () {}), readStatus().catch(function () { return null; })])
            .then(function (res) {
                const st = res[1];
                if (st && st.stage) {
                    setPercent(stagePercent[st.stage] || bestPercent);
                    stageEl.textContent = (labels.stages[st.stage] || st.stage);
                }
                if (st && ['success', 'failed', 'rolled_back', 'rollback_failed'].indexOf(st.status) !== -1) {
                    readLog().catch(function () {}).then(function () { finish(st); });
                    return;
                }
                // Nothing at all for 20 minutes: say so instead of spinning.
                if (Date.now() - lastChange > 20 * 60 * 1000) {
                    showResult('danger', labels.lost);
                    reloadBtn.hidden = false;
                    return;
                }
                setTimeout(poll, 1000);
            });
    }

    document.getElementById('upd-confirm-yes').addEventListener('click', function () {
        const yes = this;
        yes.disabled = true;
        fetch(runUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf ? csrf.content : '' },
            credentials: 'same-origin',
            body: JSON.stringify({ stamp: stamp })
        })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                closeModal();
                area.hidden = false;
                btn.disabled = true;
                if (!res.ok) {
                    showResult('danger', res.body.error || res.body.message || 'Error');
                    btn.disabled = false;
                    yes.disabled = false;
                    return;
                }
                logUrl = res.body.log_url;
                statusUrl = res.body.status_url;
                lastChange = Date.now();
                setPercent(2);
                stageEl.textContent = labels.stages.queued;
                poll();
            })
            .catch(function (e) {
                closeModal();
                area.hidden = false;
                showResult('danger', String(e));
                yes.disabled = false;
            });
    });

    reloadBtn.addEventListener('click', function () { window.location.reload(); });
})();
</script>
@endif
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
