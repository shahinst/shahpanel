@if ($panel === 'admin' && Route::has('admin.dashboard.server-stats'))
    <h6 class="panel-dash-section-title d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>{{ __('dashboard.server_monitor') }}</span>
        <small class="text-muted fw-normal" id="server-monitor-clock">{{ __('dashboard.server_monitor_loading') }}</small>
    </h6>

    <div id="server-monitor-grid" class="row g-3 mb-4">
        <div class="col-12">
            <div class="panel-chart-empty">{{ __('dashboard.server_monitor_loading') }}</div>
        </div>
    </div>

    @push('styles')
    <style>
    .server-monitor-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: .85rem .95rem;
        height: 100%;
    }
    .server-monitor-card.is-offline { opacity: .82; }
    .server-monitor-card .head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: .5rem;
        margin-bottom: .65rem;
    }
    .server-monitor-card .name {
        font-weight: 700;
        color: #0f172a;
        font-size: .88rem;
        margin-bottom: .1rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }
    .server-monitor-card .host {
        font-size: .68rem;
        color: #64748b;
        direction: ltr;
        text-align: left;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .server-monitor-status {
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        padding: .15rem .45rem;
        border-radius: 999px;
        font-size: .65rem;
        font-weight: 700;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .server-monitor-status--healthy { background: #ecfdf5; color: #059669; }
    .server-monitor-status--warning { background: #fffbeb; color: #d97706; }
    .server-monitor-status--critical { background: #fef2f2; color: #dc2626; }
    .server-monitor-status--offline { background: #f1f5f9; color: #64748b; }

    .server-monitor-gauges {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: .5rem;
        margin-bottom: .65rem;
    }
    .server-monitor-gauge {
        text-align: center;
        min-width: 0;
    }
    .server-monitor-gauge .gauge-label {
        font-size: .7rem;
        color: #64748b;
        margin-top: .3rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sm-ring {
        --size: 78px;
        --track: #e8edf3;
        --color: #6366f1;
        --percent: 0;
        width: var(--size);
        height: var(--size);
        margin: 0 auto;
        position: relative;
    }
    .sm-ring__donut {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: conic-gradient(var(--color) calc(var(--percent) * 1%), var(--track) 0);
        transition: background .35s ease;
        -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 9px), #000 calc(100% - 8px));
        mask: radial-gradient(farthest-side, transparent calc(100% - 9px), #000 calc(100% - 8px));
    }
    .sm-ring__donut.is-split {
        background: conic-gradient(
            #06b6d4 0deg calc(var(--up-ratio, 50) * 3.6deg),
            #6366f1 calc(var(--up-ratio, 50) * 3.6deg) 360deg
        );
    }
    .sm-ring__center {
        position: absolute;
        inset: 12px;
        border-radius: 50%;
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        font-size: .72rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.2;
        padding: 2px;
    }
    .sm-ring__center small {
        font-size: .58rem;
        font-weight: 600;
        color: #64748b;
    }
    .sm-ring__center .net-up { color: #0891b2; }
    .sm-ring__center .net-down { color: #6366f1; }

    .server-monitor-meta {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem .65rem;
        font-size: .65rem;
        color: #64748b;
        border-top: 1px solid #f1f5f9;
        padding-top: .45rem;
    }
    .server-monitor-error {
        margin-top: .45rem;
        font-size: .68rem;
        color: #dc2626;
        word-break: break-word;
    }
    @media (max-width: 575px) {
        .server-monitor-gauges { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    </style>
    @endpush

    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.getElementById('server-monitor-grid');
        var clock = document.getElementById('server-monitor-clock');
        if (!grid) return;

        var endpoint = @json(route('admin.dashboard.server-stats'));
        var pollIntervalMs = @json(max(10, (int) config('shahpanel.server_monitor.poll_interval_seconds', 30)) * 1000);
        var fetchTimeoutMs = @json(max(5, (int) config('shahpanel.server_monitor.fetch_timeout_seconds', 12)) * 1000);
        var pollInFlight = false;
        var pollAbort = null;
        var labels = {
            healthy: @json(__('dashboard.server_health_healthy')),
            warning: @json(__('dashboard.server_health_warning')),
            critical: @json(__('dashboard.server_health_critical')),
            offline: @json(__('dashboard.server_health_offline')),
            cpu: @json(__('dashboard.server_cpu')),
            memory: @json(__('dashboard.server_memory')),
            disk: @json(__('dashboard.server_disk')),
            network: @json(__('dashboard.server_network')),
            uptime: @json(__('dashboard.server_uptime')),
            xray: @json(__('dashboard.server_xray')),
            load: @json(__('dashboard.server_load')),
            noServers: @json(__('dashboard.server_monitor_empty')),
            fetchError: @json(__('dashboard.server_monitor_error')),
            na: '—',
        };

        function gaugeColor(percent) {
            if (percent >= 90) return '#dc2626';
            if (percent >= 75) return '#d97706';
            return null;
        }

        function toneColor(tone, percent) {
            var alert = gaugeColor(percent);
            if (alert) return alert;
            if (tone === 'cpu') return '#6366f1';
            if (tone === 'mem') return '#2563eb';
            if (tone === 'disk') return '#059669';
            return '#0891b2';
        }

        function ringGauge(label, percent, centerHtml, tone, extraStyle) {
            var value = Math.max(0, Math.min(100, Number(percent) || 0));
            var color = toneColor(tone, value);
            var style = '--percent:' + value + ';--color:' + color + ';' + (extraStyle || '');

            return '<div class="server-monitor-gauge">' +
                '<div class="sm-ring" style="' + style + '">' +
                    '<div class="sm-ring__donut' + (tone === 'net' ? ' is-split' : '') + '"></div>' +
                    '<div class="sm-ring__center">' + centerHtml + '</div>' +
                '</div>' +
                '<div class="gauge-label">' + label + '</div>' +
            '</div>';
        }

        function renderServers(servers) {
            if (!servers.length) {
                grid.innerHTML = '<div class="col-12"><div class="panel-chart-empty">' + labels.noServers + '</div></div>';
                return;
            }

            grid.innerHTML = servers.map(function (server) {
                var statusClass = 'server-monitor-status--' + (server.health || 'offline');
                var statusLabel = labels[server.health] || labels.offline;
                var cardClass = server.online ? '' : ' is-offline';

                var cpu = server.cpu_percent;
                var mem = server.memory ? server.memory.percent : null;
                var disk = server.disk ? server.disk.percent : null;

                var cpuRing = ringGauge(
                    labels.cpu,
                    cpu,
                    cpu !== null && cpu !== undefined ? Math.round(cpu) + '%' : labels.na,
                    'cpu'
                );

                var memRing = ringGauge(
                    labels.memory,
                    mem,
                    mem !== null && mem !== undefined ? Math.round(mem) + '%' : labels.na,
                    'mem'
                );

                var diskRing = ringGauge(
                    labels.disk,
                    disk,
                    disk !== null && disk !== undefined ? Math.round(disk) + '%' : labels.na,
                    'disk'
                );

                var netRing;
                if (server.network) {
                    var net = server.network;
                    var netCenter = '<span class="net-up">↑' + (net.up || '0') + '</span>' +
                        '<span class="net-down">↓' + (net.down || '0') + '</span>';
                    netRing = ringGauge(
                        labels.network,
                        net.percent || 0,
                        netCenter,
                        'net',
                        '--up-ratio:' + (net.up_ratio || 50) + ';'
                    );
                } else {
                    netRing = ringGauge(labels.network, 0, labels.na, 'net');
                }

                var meta = [];
                if (server.uptime) meta.push(labels.uptime + ': ' + server.uptime);
                if (server.xray_state) meta.push(labels.xray + ': ' + server.xray_state);
                if (server.load && server.load.length) meta.push(labels.load + ': ' + server.load.join(' / '));
                if (server.tcp_count) meta.push('TCP: ' + server.tcp_count);

                var error = server.error ? '<div class="server-monitor-error">' + server.error + '</div>' : '';

                return '<div class="col-lg-6 col-12">' +
                    '<div class="server-monitor-card' + cardClass + '">' +
                        '<div class="head">' +
                            '<div style="min-width:0"><div class="name" title="' + server.name + '">' + server.name + '</div>' +
                            '<div class="host">' + server.host + ':' + server.port + ' · ' + server.type + '</div></div>' +
                            '<span class="server-monitor-status ' + statusClass + '">' + statusLabel + '</span>' +
                        '</div>' +
                        '<div class="server-monitor-gauges">' + cpuRing + memRing + diskRing + netRing + '</div>' +
                        (meta.length ? '<div class="server-monitor-meta">' + meta.map(function (item) { return '<span>' + item + '</span>'; }).join('') + '</div>' : '') +
                        error +
                    '</div>' +
                '</div>';
            }).join('');
        }

        function poll() {
            if (pollInFlight) {
                return;
            }

            pollInFlight = true;

            if (pollAbort) {
                pollAbort.abort();
            }

            pollAbort = new AbortController();
            var timeoutId = setTimeout(function () {
                pollAbort.abort();
            }, fetchTimeoutMs);

            fetch(endpoint, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                signal: pollAbort.signal
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(function (data) {
                    renderServers(data.servers || []);
                    if (!clock) return;

                    var timeText = data.polled_at
                        ? new Date(data.polled_at).toLocaleTimeString(@json(locale_tag()))
                        : '';

                    clock.textContent = @json(__('dashboard.server_monitor_updated')) + ' ' + timeText;

                    if (data.stale) {
                        clock.textContent += ' · ' + @json(__('dashboard.server_monitor_syncing'));
                    }
                })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }

                    if (clock) clock.textContent = labels.fetchError;
                })
                .finally(function () {
                    clearTimeout(timeoutId);
                    pollInFlight = false;
                });
        }

        poll();
        setInterval(poll, pollIntervalMs);
    });
    </script>
    @endpush
@endif
