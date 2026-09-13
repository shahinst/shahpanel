@if ($panel === 'admin' && Route::has('admin.dashboard.system-health'))
@php
    $initialHealth = $initialHealth ?? null;
@endphp

<h6 class="panel-dash-section-title d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span>{{ __('dashboard.health.title') }}</span>
    <small class="text-muted fw-normal" id="system-health-clock">{{ __('dashboard.health.loading') }}</small>
</h6>

<div id="system-health-root" class="mb-4" data-initial='@json($initialHealth)'>
    <div class="panel-chart-empty">{{ __('dashboard.health.loading') }}</div>
</div>

@push('styles')
<style>
.health-panel-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1rem 1.1rem;
    height: 100%;
}
.health-panel-card__title {
    font-size: .88rem;
    font-weight: 700;
    color: #334155;
    margin-bottom: .75rem;
}
.health-host-meta {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem .75rem;
    font-size: .72rem;
    color: #64748b;
    margin-bottom: .75rem;
}
.health-metric-row {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .75rem;
}
.health-metric {
    border: 1px solid #f1f5f9;
    border-radius: 12px;
    padding: .65rem .7rem;
}
.health-metric__label {
    font-size: .72rem;
    color: #64748b;
    margin-bottom: .35rem;
}
.health-metric__value {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}
.health-progress {
    height: 6px;
    border-radius: 999px;
    background: #e2e8f0;
    overflow: hidden;
    margin-top: .4rem;
}
.health-progress > span {
    display: block;
    height: 100%;
    border-radius: inherit;
    transition: width .35s ease;
}
.health-services-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: .65rem;
}
.health-service-pill {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: .6rem .75rem;
    background: #fafbfc;
}
.health-service-pill__label {
    font-size: .78rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: .2rem;
}
.health-service-pill__detail {
    font-size: .68rem;
    color: #64748b;
    line-height: 1.4;
}
.health-status {
    display: inline-flex;
    align-items: center;
    gap: .25rem;
    padding: .12rem .45rem;
    border-radius: 999px;
    font-size: .64rem;
    font-weight: 700;
}
.health-status--healthy { background: #ecfdf5; color: #059669; }
.health-status--warning { background: #fffbeb; color: #d97706; }
.health-status--critical { background: #fef2f2; color: #dc2626; }
.health-status--unknown { background: #f1f5f9; color: #64748b; }

.health-cron-table {
    width: 100%;
    font-size: .78rem;
}
.health-cron-table th {
    color: #64748b;
    font-weight: 600;
    border-bottom: 1px solid #e2e8f0;
    padding: .45rem .5rem;
}
.health-cron-table td {
    border-bottom: 1px solid #f1f5f9;
    padding: .5rem;
    vertical-align: top;
}
.health-cron-table code { font-size: .7rem; direction: ltr; display: inline-block; }
@media (max-width: 767px) {
    .health-metric-row { grid-template-columns: 1fr; }
}
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('system-health-root');
    var clock = document.getElementById('system-health-clock');
    if (!root) return;

    var endpoint = @json(route('admin.dashboard.system-health'));
    var labels = {
        hostTitle: @json(__('dashboard.health.host_title')),
        servicesTitle: @json(__('dashboard.health.services_title')),
        cronTitle: @json(__('dashboard.health.cron_title')),
        cpu: @json(__('dashboard.server_cpu')),
        memory: @json(__('dashboard.server_memory')),
        disk: @json(__('dashboard.server_disk')),
        load: @json(__('dashboard.server_load')),
        uptime: @json(__('dashboard.health.uptime')),
        healthy: @json(__('dashboard.server_health_healthy')),
        warning: @json(__('dashboard.server_health_warning')),
        critical: @json(__('dashboard.server_health_critical')),
        unknown: @json(__('dashboard.health.cron_status_unknown')),
        offline: @json(__('dashboard.server_health_offline')),
        required: @json(__('dashboard.health.required')),
        optional: @json(__('dashboard.health.optional')),
        lastRun: @json(__('dashboard.health.last_run')),
        never: @json(__('dashboard.health.never')),
        fetchError: @json(__('dashboard.health.fetch_error')),
        updated: @json(__('dashboard.server_monitor_updated')),
        na: '—',
    };

    function statusBadge(status, text) {
        var cls = 'health-status--' + (status || 'unknown');
        return '<span class="health-status ' + cls + '">' + (text || labels.unknown) + '</span>';
    }

    function progressBar(percent) {
        var value = Math.max(0, Math.min(100, Number(percent) || 0));
        var color = value >= 90 ? '#dc2626' : (value >= 75 ? '#d97706' : '#6366f1');
        return '<div class="health-progress"><span style="width:' + value + '%;background:' + color + '"></span></div>';
    }

    function metricBlock(label, block, percentFallback) {
        if (!block) {
            return '<div class="health-metric"><div class="health-metric__label">' + label + '</div><div class="health-metric__value">' + labels.na + '</div></div>';
        }
        var pct = block.percent !== undefined ? block.percent : percentFallback;
        return '<div class="health-metric"><div class="health-metric__label">' + label + '</div>' +
            '<div class="health-metric__value">' + Math.round(pct) + '%</div>' +
            '<div class="text-muted" style="font-size:.65rem">' + (block.used_label || '') + ' / ' + (block.total_label || '') + '</div>' +
            progressBar(pct) + '</div>';
    }

    function renderHost(host) {
        var statusLabel = labels[host.health] || labels.unknown;
        var meta = [
            host.hostname ? ('Host: ' + host.hostname) : null,
            host.os ? ('OS: ' + host.os) : null,
            host.php_version ? ('PHP ' + host.php_version) : null,
            host.cpu_cores ? ('Cores: ' + host.cpu_cores) : null,
        ].filter(Boolean);

        var load = (host.load && host.load.length) ? (labels.load + ': ' + host.load.join(' / ')) : '';
        var uptime = host.uptime_seconds ? (labels.uptime + ': ' + Math.floor(host.uptime_seconds / 3600) + 'h') : '';

        return '<div class="col-12"><div class="health-panel-card">' +
            '<div class="d-flex justify-content-between align-items-start gap-2 mb-2">' +
                '<div class="health-panel-card__title">' + labels.hostTitle + '</div>' +
                statusBadge(host.health, statusLabel) +
            '</div>' +
            '<div class="health-host-meta">' + meta.map(function (m) { return '<span>' + m + '</span>'; }).join('') +
                (load ? '<span>' + load + '</span>' : '') +
                (uptime ? '<span>' + uptime + '</span>' : '') +
            '</div>' +
            '<div class="health-metric-row">' +
                '<div class="health-metric"><div class="health-metric__label">' + labels.cpu + '</div><div class="health-metric__value">' +
                    (host.cpu_percent !== null && host.cpu_percent !== undefined ? Math.round(host.cpu_percent) + '%' : labels.na) +
                '</div>' + (host.cpu_percent !== null ? progressBar(host.cpu_percent) : '') + '</div>' +
                metricBlock(labels.memory, host.memory) +
                metricBlock(labels.disk, host.disk) +
            '</div>' +
            (host.error ? '<div class="text-danger small mt-2">' + host.error + '</div>' : '') +
        '</div></div>';
    }

    function renderServices(services) {
        var pills = (services || []).map(function (svc) {
            return '<div class="health-service-pill">' +
                '<div class="d-flex justify-content-between gap-1"><span class="health-service-pill__label">' + svc.label + '</span>' + statusBadge(svc.status) + '</div>' +
                '<div class="health-service-pill__detail">' + (svc.detail || '') + '</div>' +
            '</div>';
        }).join('');

        return '<div class="col-12"><div class="health-panel-card">' +
            '<div class="health-panel-card__title">' + labels.servicesTitle + '</div>' +
            '<div class="health-services-grid">' + pills + '</div>' +
        '</div></div>';
    }

    function renderCron(jobs) {
        var rows = (jobs || []).map(function (job) {
            return '<tr>' +
                '<td>' + statusBadge(job.status, job.status_label) + '</td>' +
                '<td><strong>' + job.label + '</strong><div class="text-muted" style="font-size:.68rem">' + (job.description || '') + '</div></td>' +
                '<td><code>' + job.cron + '</code><div class="text-muted" style="font-size:.68rem">' + job.internal + '</div></td>' +
                '<td>' + (job.required ? labels.required : labels.optional) + '</td>' +
                '<td>' + (job.last_run_human || labels.never) + '</td>' +
            '</tr>';
        }).join('');

        return '<div class="col-12"><div class="health-panel-card">' +
            '<div class="health-panel-card__title">' + labels.cronTitle + '</div>' +
            '<div class="table-responsive"><table class="health-cron-table"><thead><tr>' +
                '<th></th><th>Job</th><th>Cron</th><th></th><th>' + labels.lastRun + '</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div>' +
        '</div></div>';
    }

    function render(data) {
        root.innerHTML = '<div class="row g-3">' +
            renderHost(data.host || {}) +
            renderServices(data.services || []) +
            renderCron(data.cron_jobs || []) +
        '</div>';
    }

    function poll() {
        fetch(endpoint, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) {
                render(data);
                if (clock && data.checked_at) {
                    clock.textContent = labels.updated + ' ' + new Date(data.checked_at).toLocaleTimeString('fa-IR');
                }
            })
            .catch(function () {
                if (clock) clock.textContent = labels.fetchError;
            });
    }

    try {
        var initial = root.getAttribute('data-initial');
        if (initial) render(JSON.parse(initial));
    } catch (e) { /* ignore */ }

    poll();
    setInterval(poll, 10000);
});
</script>
@endpush
@endif
