@extends('layouts.panel')

@section('page_title', $group->name)

@section('panel_content')
@include('tunneling::_queue-status')

@if (! empty($configureState))
    @if (($configureState['status'] ?? '') === 'running')
        <x-alert type="info" class="mb-3">
            {{ __('tunneling.configure_running_banner', [
                'step' => __('tunneling.configure_step_'.($configureState['step'] ?? 'starting')),
                'started' => $configureState['started_at'] ?? '—',
            ]) }}
        </x-alert>
        <meta http-equiv="refresh" content="8">
    @elseif (($configureState['status'] ?? '') === 'failed')
        <x-alert type="error" class="mb-3">
            {{ __('tunneling.configure_failed_banner', ['message' => $configureState['message'] ?? '—']) }}
        </x-alert>
    @elseif (($configureState['status'] ?? '') === 'done')
        <x-alert type="success" class="mb-3">
            {{ __('tunneling.configure_done_banner') }}
        </x-alert>
    @endif
@endif

@if (! empty($wipeState))
    @if (($wipeState['status'] ?? '') === 'running')
        <x-alert type="warning" class="mb-3">
            {{ __('tunneling.wipe_running_banner', ['started' => $wipeState['started_at'] ?? '—']) }}
        </x-alert>
    @elseif (($wipeState['status'] ?? '') === 'failed')
        <x-alert type="error" class="mb-3">
            {{ __('tunneling.wipe_failed_banner', ['message' => $wipeState['message'] ?? '—']) }}
        </x-alert>
    @elseif (($wipeState['status'] ?? '') === 'done' && ! empty($wipeState['finished_at']))
        <x-alert type="success" class="mb-3">
            {{ __('tunneling.wipe_done_banner') }}
        </x-alert>
    @endif
@endif

@include('partials.panel-page-hero', [
    'title' => __('tunneling.group').': '.$group->name,
    'subtitle' => implode(' + ', $group->kindMixLabels()).' — '.($group->iranServer?->name ?? '—').' ← '.$group->exits->map(fn ($e) => $e->server?->name)->filter()->implode('، '),
    'icon' => 'bx-git-branch',
])

@php $configureResult = $group->meta['configure_result'] ?? null; @endphp
@if ($configureResult)
<div class="panel-modern-card mb-3">
    <div class="card-head">
        <h3><i class="bx bx-check-shield align-middle"></i> {{ __('tunneling.configure_result_title') }}</h3>
    </div>
    <div class="card-body">
        <x-alert :type="($configureResult['success'] ?? false) ? 'success' : 'warning'" class="mb-3">
            {{ ($configureResult['success'] ?? false) ? __('tunneling.configure_result_success') : __('tunneling.configure_result_partial') }}
            — {{ isset($configureResult['finished_at']) ? \Illuminate\Support\Carbon::parse($configureResult['finished_at'])->diffForHumans() : '' }}
        </x-alert>
        <div class="row g-3">
            <div class="col-md-4">
                <strong>{{ __('tunneling.configure_result_apply') }}</strong>
                <ul class="small mb-0 mt-1">
                    <li>{{ __('ui.tunneling_apply_created') }}: {{ persian_digits($configureResult['apply']['created'] ?? 0) }}</li>
                    <li>{{ __('ui.tunneling_apply_updated') }}: {{ persian_digits($configureResult['apply']['updated'] ?? 0) }}</li>
                    <li>{{ __('ui.label_error') }}: {{ persian_digits($configureResult['apply']['failed'] ?? 0) }}</li>
                </ul>
            </div>
            <div class="col-md-4">
                <strong>{{ __('tunneling.configure_result_traffic') }}</strong>
                <p class="small mb-0 mt-1">
                    {{ persian_digits($configureResult['summary']['traffic_up'] ?? 0) }}/{{ persian_digits($configureResult['summary']['traffic_total'] ?? 0) }} {{ __('tunneling.health_up') }}
                    — MTU: {{ isset($configureResult['summary']['mtu_effective']) ? persian_digits($configureResult['summary']['mtu_effective']) : '—' }}
                </p>
            </div>
            <div class="col-md-4">
                <strong>{{ __('tunneling.configure_result_wg') }}</strong>
                <ul class="small mb-0 mt-1">
                    @forelse ($configureResult['wireguard_interfaces'] ?? [] as $wg)
                        <li><code>{{ $wg['name'] }}</code> — {{ $wg['subnet'] }} ({{ $wg['status'] }})</li>
                    @empty
                        <li class="text-muted">—</li>
                    @endforelse
                </ul>
            </div>
        </div>
        @if (! empty($configureResult['agents']))
            <div class="table-responsive mt-3">
                <table class="table table-sm table-bordered mb-0">
                    <thead><tr><th>#</th><th>{{ __('tunneling.kind') }}</th><th>{{ __('tunneling.exit') }}</th><th>{{ __('tunneling.interface') }}</th><th>{{ __('tunneling.transport') }}</th><th>{{ __('tunneling.health') }}</th></tr></thead>
                    <tbody>
                        @foreach ($configureResult['agents'] as $row)
                            <tr>
                                <td>{{ persian_digits($row['seq'] ?? '—') }}</td>
                                <td>{{ $row['kind'] ?? '—' }}</td>
                                <td>{{ $row['exit'] ?? '—' }}</td>
                                <td><code>{{ $row['iran_interface'] ?? '—' }}</code></td>
                                <td dir="ltr"><code>{{ $row['transport'] ?? '—' }}</code></td>
                                <td>{{ __('tunneling.health_'.($row['health'] ?? 'down')) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endif

{{-- Status + actions --}}
<div class="panel-modern-card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="badge bg-{{ $group->status->cssClass() }}" id="tg-status-badge">{{ $group->status->label() }}</span>
        @if ($group->status_message)
            <small class="text-danger" id="tg-status-message">{{ $group->status_message }}</small>
        @endif
        <span class="text-muted small">
            {{ __('tunneling.mtu') }}: {{ $group->effectiveMtu() !== null ? persian_digits($group->effectiveMtu()) : '—' }}
            ({{ __('tunneling.mtu_calculated') }}: {{ $group->mtu_calculated !== null ? persian_digits($group->mtu_calculated) : '—' }}
            / {{ __('tunneling.mtu_probed') }}: {{ $group->mtu_probed !== null ? persian_digits($group->mtu_probed) : '—' }})
        </span>
        <span class="text-muted small">
            {{ __('tunneling.direction') }}: {{ $group->isReverse() ? __('tunneling.direction_reverse') : __('tunneling.direction_normal') }}
        </span>
        @if ($group->last_applied_at)
            <span class="text-muted small">{{ __('tunneling.last_applied') }}: {{ $group->last_applied_at->diffForHumans() }}</span>
        @endif

        <div class="ms-auto d-flex flex-wrap gap-1">
            <a href="#tunnel-events" class="btn btn-sm btn-outline-secondary"><i class="bx bx-list-ul"></i> {{ __('tunneling.events') }}</a>
            <a href="#tunnel-errors" class="btn btn-sm btn-outline-danger"><i class="bx bx-error"></i> {{ __('tunneling.apply_errors') }}</a>
            <form method="POST" action="{{ route('admin.tunneling.groups.wipe-and-apply', $group) }}"
                  onsubmit="return confirm(@js(__('tunneling.wipe_and_apply_confirm')))">@csrf
                <button class="btn btn-sm btn-danger"><i class="bx bx-eraser"></i> {{ __('tunneling.wipe_and_apply') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.tunneling.groups.wipe-routers', $group) }}"
                  onsubmit="return confirm(@js(__('tunneling.wipe_routers_confirm')))">@csrf
                <button class="btn btn-sm btn-outline-danger"><i class="bx bx-trash"></i> {{ __('tunneling.wipe_routers_only') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.tunneling.groups.configure-test', $group) }}"
                  @if (! empty($configureState) && ($configureState['status'] ?? '') === 'running') onsubmit="return false" @endif>@csrf
                <button class="btn btn-sm btn-primary" @disabled(! empty($configureState) && ($configureState['status'] ?? '') === 'running')>
                    <i class="bx bx-play-circle"></i> {{ __('tunneling.configure_and_test') }}
                </button>
            </form>
            <form method="POST" action="{{ route('admin.tunneling.groups.reconcile', $group) }}">@csrf
                <button class="btn btn-sm btn-light"><i class="bx bx-refresh"></i> {{ __('tunneling.reconcile_now') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.tunneling.groups.probe-mtu', $group) }}">@csrf
                <button class="btn btn-sm btn-light"><i class="bx bx-ruler"></i> {{ __('tunneling.probe_mtu') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.tunneling.groups.traffic-test', $group) }}">@csrf
                <button class="btn btn-sm btn-light"><i class="bx bx-pulse"></i> {{ __('tunneling.traffic_test') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.tunneling.groups.reverse', $group) }}">@csrf
                <button class="btn btn-sm btn-warning"><i class="bx bx-transfer"></i> {{ __('tunneling.reverse') }}</button>
            </form>
            <a href="{{ route('admin.tunneling.groups.edit', $group) }}" class="btn btn-sm btn-light"><i class="bx bx-edit"></i> {{ __('app.edit') }}</a>
            <form method="POST" action="{{ route('admin.tunneling.groups.delete', $group) }}"
                  onsubmit="return confirm(@js(__('tunneling.teardown_confirm')))">
                @csrf
                <button class="btn btn-sm btn-danger" type="submit">
                    <i class="bx bx-trash"></i> {{ __('tunneling.teardown') }}
                </button>
            </form>
        </div>
    </div>
</div>

@if ($group->kindSelectionMode() === 'priority' && count($group->priorityKindOrder()) > 1)
    <div class="panel-modern-card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-2">
            <strong class="small">{{ __('tunneling.kind_failover_title') }}:</strong>
            @foreach ($group->priorityKindOrder() as $kind)
                @php $isActive = $kind === $group->activeKind(); @endphp
                @if ($isActive)
                    <span class="badge bg-success">{{ $kind->label() }} — {{ __('tunneling.kind_failover_active') }}</span>
                @else
                    <form method="POST" action="{{ route('admin.tunneling.groups.promote-kind', $group) }}">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $kind->value }}">
                        <button class="btn btn-sm btn-outline-warning">
                            <i class="bx bx-shuffle"></i> {{ __('tunneling.kind_failover_switch_to', ['kind' => $kind->label()]) }}
                        </button>
                    </form>
                @endif
            @endforeach
        </div>
    </div>
@endif

@if ($failedObjects->isNotEmpty() || $group->status_message)
    <div class="panel-modern-card mb-3 border-danger" id="tunnel-errors">
        <div class="card-head"><h3 class="text-danger mb-0"><i class="bx bx-error-circle"></i> {{ __('tunneling.apply_errors') }}</h3></div>
        <div class="card-body">
            @if ($group->status_message)
                <x-alert type="error" class="mb-3">{{ $group->status_message }}</x-alert>
            @endif
            <p class="small text-muted">{{ __('tunneling.apply_errors_hint') }}</p>
            @if ($failedObjects->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('tunneling.error_server') }}</th>
                                <th>{{ __('tunneling.error_object') }}</th>
                                <th>{{ __('tunneling.error_message') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($failedObjects as $object)
                                <tr>
                                    <td>{{ $object->server?->name ?? '—' }}</td>
                                    <td dir="ltr"><code class="small">{{ $object->menu }}</code><br><code class="small text-muted">{{ $object->marker }}</code></td>
                                    <td class="small text-danger">{{ $object->last_error ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-muted mb-0">{{ __('tunneling.no_apply_errors') }}</p>
            @endif
        </div>
    </div>
@endif

<div class="panel-modern-card mb-3" id="tunnel-events">
    <div class="card-head d-flex align-items-center justify-content-between">
        <h3 class="mb-0">{{ __('tunneling.events') }}</h3>
        <span class="badge bg-secondary">{{ persian_digits($events->count()) }}</span>
    </div>
    <div class="card-body" style="max-height: 360px; overflow-y: auto">
        @forelse ($events as $event)
            <div class="d-flex gap-2 border-bottom py-2">
                <i class="bx {{ $event->level === 'error' ? 'bx-x-circle text-danger' : ($event->level === 'warning' ? 'bx-error text-warning' : 'bx-check-circle text-success') }} mt-1"></i>
                <div class="flex-grow-1">
                    <div class="small">{{ $event->message }}</div>
                    @if (is_array($event->detail['failures'] ?? null))
                        <ul class="small text-danger mb-1 ps-3">
                            @foreach ($event->detail['failures'] as $failure)
                                <li dir="ltr"><code>{{ $failure['menu'] ?? '' }}</code> — {{ $failure['error'] ?? '' }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <small class="text-muted">{{ $event->created_at->diffForHumans() }} — {{ $event->action }}</small>
                </div>
            </div>
        @empty
            <div class="text-center text-muted py-3">{{ __('tunneling.no_events') }}</div>
        @endforelse
    </div>
</div>

{{-- Agents --}}
@php
    $lastTestByAgent = collect($group->meta['last_test']['agents'] ?? [])->keyBy('agent_id');
@endphp
<div class="panel-modern-card mb-3">
    <div class="card-head"><h3>{{ __('tunneling.agents') }}</h3></div>
    <div class="card-body">
        <x-table :headers="[__('tunneling.interface'), __('tunneling.exit'), __('tunneling.kind'), __('tunneling.transport'), __('tunneling.udp_port'), __('tunneling.health'), __('tunneling.score'), __('tunneling.weight'), __('app.actions')]">
            @forelse ($group->exits as $exit)
                @foreach ($exit->agents as $agent)
                    <tr @class(['table-secondary' => ! $agent->is_enabled])>
                        <td><code>{{ $agent->iran_interface }}</code></td>
                        <td>{{ $exit->server?->name ?? '—' }}</td>
                        <td>{{ $agent->kind->label() }}</td>
                        <td dir="ltr"><code>{{ $agent->iran_ip }} ↔ {{ $agent->foreign_ip }}</code></td>
                        <td>{{ $agent->udp_port !== null ? persian_digits($agent->udp_port) : '—' }}</td>
                        <td>
                            @php $testRow = $lastTestByAgent->get($agent->id); @endphp
                            <span class="badge bg-{{ $agent->health->cssClass() }}"
                                  @if ($testRow) title="{{ __('tunneling.health_running_hint', [
                                      'iran' => ! empty($testRow['iran_running']) ? __('tunneling.health_running_yes') : __('tunneling.health_running_no'),
                                      'foreign' => ! empty($testRow['foreign_running']) ? __('tunneling.health_running_yes') : __('tunneling.health_running_no'),
                                  ]) }}" @endif>{{ __('tunneling.health_'.$agent->health->value) }}</span>
                            @if ($testRow && ! empty($testRow['iran_running']) && ! empty($testRow['foreign_running']) && empty($testRow['ping_up']))
                                <small class="text-muted d-block">RouterOS ✓</small>
                            @endif
                        </td>
                        <td>{{ $agent->quality_score !== null ? persian_digits($agent->quality_score) : '—' }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.tunneling.agents.weight', $agent) }}" class="d-flex gap-1" style="max-width: 130px">
                                @csrf
                                <input name="weight" type="number" min="1" max="10" value="{{ $agent->weight }}" class="form-control form-control-sm" style="width: 60px">
                                <button class="btn btn-sm btn-light" title="{{ __('tunneling.save_weight') }}"><i class="bx bx-check"></i></button>
                            </form>
                        </td>
                        <td class="text-nowrap">
                            <form method="POST" action="{{ route('admin.tunneling.agents.toggle', $agent) }}" class="d-inline">@csrf
                                <button class="btn btn-sm {{ $agent->is_enabled ? 'btn-outline-danger' : 'btn-outline-success' }}" title="{{ __('tunneling.toggle') }}">
                                    <i class="bx {{ $agent->is_enabled ? 'bx-pause' : 'bx-play' }}"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.tunneling.agents.switch', $agent) }}" class="d-inline">@csrf
                                <button class="btn btn-sm btn-outline-warning" title="{{ __('tunneling.switch_now') }}"><i class="bx bx-shuffle"></i></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">{{ __('app.no_results') }}</td></tr>
            @endforelse
        </x-table>
    </div>
</div>

{{-- Charts --}}
<div class="row">
    <div class="col-lg-6">
        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3>{{ __('tunneling.chart_latency') }}</h3></div>
            <div class="card-body"><canvas id="tg-chart-latency" height="160"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3>{{ __('tunneling.chart_throughput') }}</h3></div>
            <div class="card-body"><canvas id="tg-chart-throughput" height="160"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3>{{ __('tunneling.chart_score') }}</h3></div>
            <div class="card-body"><canvas id="tg-chart-score" height="160"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3>{{ __('tunneling.chart_capacity') }}</h3></div>
            <div class="card-body"><canvas id="tg-chart-capacity" height="160"></canvas></div>
        </div>
    </div>
</div>

<div class="row">
    {{-- Monitoring scripts --}}
    <div class="col-lg-6">
        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3>{{ __('tunneling.monitoring_scripts') }}</h3></div>
            <div class="card-body">
                @php
                    $allServers = collect([$group->iranServer])->merge($group->exits->map->server)->filter()->unique('id');
                @endphp
                <x-table :headers="[__('servers.name'), __('app.status'), __('tunneling.last_report'), __('app.actions')]">
                    @foreach ($allServers as $server)
                        @php $script = $scripts->get($server->id); @endphp
                        <tr>
                            <td>{{ $server->name }}</td>
                            <td>
                                @if ($script?->status === 'installed')
                                    <span class="badge bg-success">{{ __('tunneling.installed') }} (v{{ persian_digits($script->version) }})</span>
                                @elseif ($script?->status === 'error')
                                    <span class="badge bg-danger" title="{{ $script->last_error }}">{{ __('tunneling.group_status_error') }}</span>
                                @else
                                    <span class="badge bg-secondary">{{ __('tunneling.not_installed') }}</span>
                                @endif
                            </td>
                            <td>{{ $script?->last_report_at?->diffForHumans() ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.tunneling.servers.install-script', $server) }}">@csrf
                                    <button class="btn btn-sm btn-light"><i class="bx bx-download"></i> {{ __('tunneling.install_script') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            </div>
        </div>

        {{-- Managed interfaces --}}
        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3>{{ __('tunneling.managed_interfaces') }}</h3></div>
            <div class="card-body">
                <x-table :headers="[__('tunneling.interface'), __('ui.col_type'), 'Subnet', __('app.status'), __('app.actions')]">
                    @forelse ($interfaces as $interface)
                        <tr>
                            <td><code>{{ $interface->name }}</code></td>
                            <td>{{ strtoupper($interface->type) }}</td>
                            <td dir="ltr"><code>{{ $interface->subnet }}</code></td>
                            <td><span class="badge bg-{{ $interface->status === 'active' ? 'success' : ($interface->status === 'removing' ? 'danger' : 'secondary') }}">{{ $interface->status }}</span></td>
                            <td>
                                <form method="POST" action="{{ route('admin.tunneling.interfaces.destroy', $interface) }}"
                                      onsubmit="return confirm(@js(__('tunneling.remove_interface').'?'))">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger"><i class="bx bx-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">{{ __('tunneling.no_interfaces') }}</td></tr>
                    @endforelse
                </x-table>

                <form method="POST" action="{{ route('admin.tunneling.interfaces.store') }}" class="d-flex flex-wrap gap-2 align-items-end mt-2">
                    @csrf
                    <input type="hidden" name="server_id" value="{{ $group->iran_server_id }}">
                    <input type="hidden" name="tunnel_group_id" value="{{ $group->id }}">
                    @if ($group->location_id !== null)
                        <input type="hidden" name="location_id" value="{{ $group->location_id }}">
                    @endif
                    <div>
                        <label class="form-label small mb-0">{{ __('ui.col_type') }}</label>
                        <select name="type" class="form-select form-select-sm">
                            <option value="wireguard">WireGuard</option>
                            <option value="ppp">PPP</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label small mb-0">{{ __('tunneling.name') }}</label>
                        <input name="name" class="form-control form-control-sm" dir="ltr" placeholder="wg-tr">
                    </div>
                    <button class="btn btn-sm btn-primary"><i class="bx bx-plus"></i> {{ __('tunneling.create_interface') }}</button>
                </form>
            </div>
        </div>
    </div>

    {{-- Config versions + events --}}
    <div class="col-lg-6">
        <div class="panel-modern-card mb-3">
            <div class="card-head"><h3>{{ __('tunneling.config_versions') }}</h3></div>
            <div class="card-body">
                <x-table :headers="[__('tunneling.version'), __('tunneling.reason'), __('ui.col_date'), __('app.actions')]">
                    @forelse ($group->configVersions->take(8) as $version)
                        <tr>
                            <td>v{{ persian_digits($version->version) }}</td>
                            <td>{{ $version->reason }}</td>
                            <td>{{ $version->created_at->diffForHumans() }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.tunneling.groups.rollback', [$group, $version]) }}">@csrf
                                    <button class="btn btn-sm btn-outline-warning"><i class="bx bx-undo"></i> {{ __('tunneling.rollback') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">{{ __('tunneling.no_versions') }}</td></tr>
                    @endforelse
                </x-table>
            </div>
        </div>

    </div>
</div>

@push('scripts')
<script>
(function () {
    if (typeof Chart === 'undefined') return;

    var metricsUrl = @js(route('admin.tunneling.groups.metrics', $group));
    var serversUrl = @js(route('admin.tunneling.groups.server-metrics', $group));
    var statusUrl = @js(route('admin.tunneling.groups.status', $group));

    var palette = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#0ea5e9', '#a855f7', '#84cc16', '#f97316'];

    function makeChart(id, yTitle) {
        var el = document.getElementById(id);
        if (!el) return null;
        return new Chart(el, {
            type: 'line',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { ticks: { maxTicksLimit: 8 } },
                    y: { beginAtZero: true, title: { display: !!yTitle, text: yTitle || '' } }
                },
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    var latencyChart = makeChart('tg-chart-latency', 'ms');
    var throughputChart = makeChart('tg-chart-throughput', 'Mbps');
    var scoreChart = makeChart('tg-chart-score', '0-100');
    var capacityChart = makeChart('tg-chart-capacity', '%');

    function applySeries(chart, series, valueKey) {
        if (!chart) return;
        var labels = [];
        series.forEach(function (s) {
            s.points.forEach(function (p) {
                if (labels.indexOf(p.t) === -1) labels.push(p.t);
            });
        });
        labels.sort();
        chart.data.labels = labels.map(function (l) { return l.slice(11); });
        chart.data.datasets = series.map(function (s, i) {
            var byTime = {};
            s.points.forEach(function (p) { byTime[p.t] = p[valueKey]; });
            return {
                label: s.label,
                data: labels.map(function (l) { return byTime[l] !== undefined ? byTime[l] : null; }),
                borderColor: palette[i % palette.length],
                backgroundColor: palette[i % palette.length] + '33',
                tension: 0.25,
                pointRadius: 0,
                spanGaps: true
            };
        });
        chart.update();
    }

    function refreshMetrics() {
        fetch(metricsUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                applySeries(latencyChart, data.series, 'latency');
                applySeries(scoreChart, data.series, 'score');
                applySeries(throughputChart, data.series.map(function (s) {
                    return { label: s.label, points: s.points.map(function (p) { return { t: p.t, v: (p.rx_mbps || 0) + (p.tx_mbps || 0) }; }) };
                }), 'v');
            })
            .catch(function () {});

        fetch(serversUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                applySeries(capacityChart, data.series.map(function (s) {
                    return { label: s.label, points: s.points.map(function (p) { return { t: p.t, v: p.cpu }; }) };
                }), 'v');
            })
            .catch(function () {});
    }

    function refreshStatus() {
        fetch(statusUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var badge = document.getElementById('tg-status-badge');
                if (badge) {
                    badge.textContent = data.status_label;
                    badge.className = 'badge bg-' + ({active: 'success', applying: 'info', removing: 'info', degraded: 'warning', down: 'danger', error: 'danger', draft: 'secondary'}[data.status] || 'secondary');
                }
            })
            .catch(function () {});
    }

    refreshMetrics();
    setInterval(refreshMetrics, 30000);
    setInterval(refreshStatus, 10000);
})();
</script>
@endpush
@endsection
