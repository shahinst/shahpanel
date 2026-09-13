# VPN Panel — Tunneling (Desired-State) Specification

This document captures invariants for the **new** MikroTik tunnel orchestrator under `app/Services/Tunneling/`. The legacy `App\Services\Tunnel\*` stack was removed; see `TUNNELING_REMOVAL_REPORT.md` for the teardown checklist.

Configuration lives in `config/tunneling.php` (marker prefix, MTU, scoring, queue, Telegram, DPI port candidates).

## Architecture

| Layer | Responsibility |
|-------|----------------|
| `TunnelGroupOrchestrator` | Sync agents, build desired objects, snapshot versions, queue apply/teardown/reverse/rollback |
| `Builders/*` | Per-kind RouterOS specs (GRE, GRE6, IPIP, EoIP, VXLAN, L2TPv2, L2TPv3 IP/UDP) |
| `LoadBalancerService` | PCC mangle + per-table routes, or weighted ECMP + return routes |
| `DesiredStateApplier` + `RouterCommandService` | Idempotent convergence by marker (`vpnl:<key>`) |
| Jobs (`app/Jobs/Tunneling/*`) | Apply, reconcile, probe MTU, traffic test, metrics, evaluate, teardown |
| Router script + `POST /tunneling/report` | On-router probes → MySQL metrics (no TSDB) |

Heavy I/O always runs on the `tunneling` database queue; cron runs `queue:work --stop-when-empty --max-time=55` every minute (see `App\Support\CronDocumentation`).

## Markers & idempotency

Every managed RouterOS row carries `comment = vpnl:<group-key>` (prefix from `config('tunneling.marker_prefix')`).

`RouterCommandService::ensure()`:

- no row → add
- one row, drift → set changed fields only
- duplicate markers → keep first, remove rest
- create-only keys (`password`, `secret`, `private-key`) are never diffed

## Tunnel kinds (8)

`TunnelKind`: GRE, GRE6, IPIP, EoIP, VXLAN, L2TPv2, L2TPv3 (IP), L2TPv3 (UDP).

Each agent may diverge from the group kind after an automatic DPI switch (`TunnelSwitchService` + L2TP ladder in `TunnelKind::l2tpLadder()`).

## MTU & MSS

- `MtuCalculator` → `mtu_calculated` from kind overhead (+ IPsec if enabled)
- `ProbeMtuJob` → DF-bit binary search on underlay; result stored as `mtu_probed`
- Effective MTU = probed ?? calculated − safety margin
- Optional MSS clamp: forward-chain mangle `change-mss clamp-to-pmtu` on both tunnel interfaces
- Iran input accepts ICMP (PMTUD)

## Load balancing & failover

- **PCC** — per-connection-classifier mangle marks spread client subnets across agents by weight; one routing table + default route per agent
- **ECMP** — single table, gateway list repeats each agent `weight` times (capped)
- Routes use `check-gateway=ping`
- `EvaluateTunnelGroupJob` + `QualityScoreService` mark agents down, reweight, trigger `TunnelSwitchService` after N failures (cooldown anti-flap)
- **Reverse** — flip `TunnelDirection`; regenerate desired state; apply converges both sides

## Monitoring

- `InstallRouterScriptJob` deploys probe script; routers POST to `tunneling.report`
- Raw samples in MySQL; `tunnels:rollup-metrics` / `tunnels:prune-metrics` for retention
- UI polls JSON endpoints every 30s (Chart.js line charts on group show page)
- `CapacityAlarmService` + optional Telegram (`config/tunneling.telegram`)

## Reconcile & rollback

- Scheduled `ReconcileServerJob` every 5 minutes for servers with desired objects
- Drift → repair via same `ensure()` path (`config('tunneling.reconcile.auto_repair')`)
- `ConfigVersion` snapshots on provision; rollback restores snapshot and re-applies

## Production migration from legacy

1. Deploy code (includes `tunnels:legacy-teardown`)
2. `php artisan tunnels:legacy-teardown` then `--apply` **before** migrate if legacy tables still exist
3. `php artisan migrate`
4. `php artisan optimize:clear && php artisan view:cache`
5. Ensure cron runs scheduler + `queue:work --queue=tunneling,default`

## Admin UI

Routes under `admin/tunneling.*` — group CRUD, configure & test, reverse, reconcile, probe MTU, traffic test, agent toggle/weight/switch, locations, managed interfaces, config versions, events, live charts.
