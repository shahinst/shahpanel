{{--
    Live console for the server's long operations (push every account, sync
    every account's usage). The work runs after the response; this card polls
    admin.servers.operation-progress and prints every account as it is done.
--}}
@php
    $op = is_array($backgroundOperation ?? null) ? $backgroundOperation : null;
@endphp
<div class="panel-modern-card mb-3 op-console-card" id="op-console" @if (! $op) hidden @endif
     data-url="{{ route('admin.servers.operation-progress', $server) }}">
    <div class="card-head d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h3 class="mb-0">
            <i class="bx bx-terminal"></i>
            <span id="op-title" data-push="{{ __('servers.progress_title_push') }}" data-traffic="{{ __('servers.progress_title_traffic') }}">
                {{ ($op['operation'] ?? '') === 'traffic' ? __('servers.progress_title_traffic') : __('servers.progress_title_push') }}
            </span>
        </h3>
        <span class="badge" id="op-status"></span>
    </div>
    <div class="card-body">
        <div class="op-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="op-progressbar">
            <div class="op-progress__fill" id="op-fill"></div>
            <span class="op-progress__label" id="op-percent">0.00%</span>
        </div>

        <div class="op-stats">
            <span><strong id="op-done">0</strong> / <strong id="op-total">0</strong></span>
            <span class="text-success"><i class="bx bx-check"></i> {{ __('servers.progress_ok') }}: <span id="op-ok">0</span></span>
            <span class="text-secondary"><i class="bx bx-skip-next"></i> {{ __('servers.progress_skipped') }}: <span id="op-skipped">0</span></span>
            <span class="text-danger"><i class="bx bx-x"></i> {{ __('servers.progress_failed') }}: <span id="op-failed">0</span></span>
            <span><i class="bx bx-time"></i> {{ __('servers.progress_elapsed') }}: <span id="op-elapsed">0s</span></span>
            <span><i class="bx bx-hourglass"></i> {{ __('servers.progress_eta') }}: <span id="op-eta">—</span></span>
            <span><i class="bx bx-tachometer"></i> <span id="op-rate">—</span> {{ __('servers.progress_rate_unit') }}</span>
        </div>

        <div class="op-toolbar">
            <label class="mb-0"><input type="checkbox" id="op-errors-only"> {{ __('servers.progress_errors_only') }}</label>
            <label class="mb-0"><input type="checkbox" id="op-autoscroll" checked> {{ __('servers.progress_autoscroll') }}</label>
            <button type="button" class="btn btn-sm btn-light" id="op-copy"><i class="bx bx-copy"></i> {{ __('servers.progress_copy') }}</button>
        </div>

        <div class="op-console" id="op-log" dir="ltr" aria-live="polite"></div>
    </div>
</div>

@push('styles')
<style>
    .op-progress { position: relative; height: 26px; border-radius: 6px; background: #e9ecef; overflow: hidden; }
    .op-progress__fill { position: absolute; inset: 0 auto 0 0; width: 0; background: linear-gradient(90deg, #1668dc, #3b8cff); transition: width .35s ease; }
    .op-progress--done .op-progress__fill { background: linear-gradient(90deg, #198754, #2fb380); }
    .op-progress--error .op-progress__fill { background: linear-gradient(90deg, #b02a37, #dc3545); }
    .op-progress--running .op-progress__fill { background-image: linear-gradient(45deg, rgba(255,255,255,.18) 25%, transparent 25%, transparent 50%, rgba(255,255,255,.18) 50%, rgba(255,255,255,.18) 75%, transparent 75%, transparent), linear-gradient(90deg, #1668dc, #3b8cff); background-size: 28px 28px, 100% 100%; animation: op-stripes 1s linear infinite; }
    @keyframes op-stripes { from { background-position: 28px 0, 0 0; } to { background-position: 0 0, 0 0; } }
    .op-progress__label { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-weight: 700; font-variant-numeric: tabular-nums; color: #111; mix-blend-mode: normal; direction: ltr; }
    .op-stats { display: flex; flex-wrap: wrap; gap: 6px 18px; margin: 10px 0; font-size: .9rem; font-variant-numeric: tabular-nums; }
    .op-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; margin-bottom: 8px; font-size: .85rem; }
    .op-console { background: #0d1117; color: #c9d1d9; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; line-height: 1.55; height: 360px; overflow: auto; border-radius: 6px; padding: 10px 12px; white-space: pre-wrap; word-break: break-word; }
    .op-console .op-line { display: block; }
    .op-console .op-t { color: #6e7681; margin-right: 8px; }
    .op-console .op-ok { color: #3fb950; }
    .op-console .op-error { color: #ff7b72; }
    .op-console .op-warn { color: #d29922; }
    .op-console .op-skip { color: #8b949e; }
    .op-console .op-info { color: #79c0ff; }
    .op-console.op-errors-only .op-line:not(.op-error) { display: none; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const card = document.getElementById('op-console');
    if (!card) return;

    const url = card.dataset.url;
    const logEl = document.getElementById('op-log');
    const bar = document.getElementById('op-progressbar');
    const fill = document.getElementById('op-fill');
    const pct = document.getElementById('op-percent');
    const statusEl = document.getElementById('op-status');
    const titleEl = document.getElementById('op-title');
    const autoScroll = document.getElementById('op-autoscroll');
    const errorsOnly = document.getElementById('op-errors-only');
    const fields = ['done', 'total', 'ok', 'skipped', 'failed'].reduce(function (acc, k) {
        acc[k] = document.getElementById('op-' + k);
        return acc;
    }, {});
    const labels = {
        running: @json(__('servers.progress_status_running')),
        success: @json(__('servers.progress_status_success')),
        warning: @json(__('servers.progress_status_warning')),
        error: @json(__('servers.progress_status_error')),
    };
    const nf = new Intl.NumberFormat('en-US');
    let lastSeq = 0;
    let timer = null;
    let failures = 0;
    let localStart = null;
    let lastElapsed = 0;
    let lastRate = null;
    let lastTotal = 0;
    let lastDone = 0;
    let running = false;

    function fmtDuration(seconds) {
        if (seconds === null || seconds === undefined || !isFinite(seconds)) return '—';
        seconds = Math.max(0, Math.round(seconds));
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        if (h > 0) return h + 'h ' + String(m).padStart(2, '0') + 'm ' + String(s).padStart(2, '0') + 's';
        if (m > 0) return m + 'm ' + String(s).padStart(2, '0') + 's';
        return s + 's';
    }

    function appendLines(lines) {
        if (!lines || !lines.length) return;
        const frag = document.createDocumentFragment();
        lines.forEach(function (line) {
            const row = document.createElement('span');
            row.className = 'op-line op-' + (line.level || 'info');
            row.setAttribute('dir', 'auto');
            const t = document.createElement('span');
            t.className = 'op-t';
            t.textContent = '+' + Number(line.t || 0).toFixed(2) + 's';
            row.appendChild(t);
            row.appendChild(document.createTextNode(line.text));
            frag.appendChild(row);
            lastSeq = Math.max(lastSeq, line.seq);
        });
        logEl.appendChild(frag);
        if (autoScroll.checked) logEl.scrollTop = logEl.scrollHeight;
    }

    function render(op) {
        card.hidden = false;
        running = op.status === 'running';
        lastTotal = op.total;
        lastDone = op.done;
        lastRate = op.rate;
        lastElapsed = op.elapsed;
        localStart = performance.now();

        titleEl.textContent = op.operation === 'traffic' ? titleEl.dataset.traffic : titleEl.dataset.push;
        const percent = Math.max(0, Math.min(100, Number(op.percent) || 0));
        fill.style.width = percent.toFixed(2) + '%';
        pct.textContent = percent.toFixed(2) + '%';
        bar.setAttribute('aria-valuenow', percent.toFixed(2));
        bar.classList.toggle('op-progress--running', running);
        bar.classList.toggle('op-progress--done', !running && op.type !== 'error');
        bar.classList.toggle('op-progress--error', !running && op.type === 'error');

        Object.keys(fields).forEach(function (k) { fields[k].textContent = nf.format(op[k] || 0); });
        document.getElementById('op-rate').textContent = op.rate ? Number(op.rate).toFixed(2) : '—';
        document.getElementById('op-elapsed').textContent = fmtDuration(op.elapsed);
        document.getElementById('op-eta').textContent = running ? fmtDuration(op.eta) : '0s';

        const state = running ? 'running' : (op.type || 'success');
        statusEl.textContent = labels[state] || state;
        statusEl.className = 'badge ' + ({ running: 'bg-primary', success: 'bg-success', warning: 'bg-warning text-dark', error: 'bg-danger' }[state] || 'bg-secondary');

        appendLines(op.lines);
    }

    // Between polls the clock and the time-left estimate keep ticking.
    function tick() {
        if (!running || localStart === null) return;
        const extra = (performance.now() - localStart) / 1000;
        document.getElementById('op-elapsed').textContent = fmtDuration(lastElapsed + extra);
        if (lastRate && lastTotal > lastDone) {
            document.getElementById('op-eta').textContent = fmtDuration(Math.max(0, (lastTotal - lastDone) / lastRate - extra));
        }
    }
    setInterval(tick, 250);

    function poll() {
        fetch(url + '?after=' + lastSeq, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (payload) {
                failures = 0;
                const op = payload.operation;
                if (!op) return;
                render(op);
                if (op.status === 'running') schedule(600);
            })
            .catch(function () {
                failures++;
                schedule(Math.min(5000, 1000 * failures));
            });
    }

    function schedule(ms) {
        clearTimeout(timer);
        timer = setTimeout(poll, ms);
    }

    errorsOnly.addEventListener('change', function () {
        logEl.classList.toggle('op-errors-only', errorsOnly.checked);
    });

    document.getElementById('op-copy').addEventListener('click', function () {
        const text = Array.from(logEl.querySelectorAll('.op-line')).map(function (l) { return l.textContent; }).join('\n');
        if (navigator.clipboard) navigator.clipboard.writeText(text).catch(function () {});
    });

    @if ($op)
    poll();
    @endif
})();
</script>
@endpush
