<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New tunneling system — desired-state data model.
 *
 * The panel stores the DESIRED state of every router object; reconciler jobs
 * compare it with the ACTUAL router state (find-by-marker-comment) and repair
 * drift. Metrics live in MySQL (raw samples pruned after 48h + hourly rollups)
 * because the production host has no TSDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // Locations: a foreign exit location (e.g. Turkey) tied to one Iran
        // entry server. Client-facing interfaces (wg-tr/ppp-tr) hang off it.
        // ---------------------------------------------------------------
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 16)->unique();
            $table->foreignId('iran_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // servers: per-role capacity cap + last capacity sample (denormalized
        // snapshot of newest server_metric_samples row for cheap UI reads).
        // ---------------------------------------------------------------
        Schema::table('servers', function (Blueprint $table): void {
            if (! Schema::hasColumn('servers', 'account_cap')) {
                $table->unsignedInteger('account_cap')->nullable()->after('max_accounts');
            }
            if (! Schema::hasColumn('servers', 'last_cpu_pct')) {
                $table->decimal('last_cpu_pct', 5, 2)->nullable()->after('account_cap');
            }
            if (! Schema::hasColumn('servers', 'last_throughput_bps')) {
                $table->unsignedBigInteger('last_throughput_bps')->nullable()->after('last_cpu_pct');
            }
            if (! Schema::hasColumn('servers', 'last_conntrack')) {
                $table->unsignedInteger('last_conntrack')->nullable()->after('last_throughput_bps');
            }
            if (! Schema::hasColumn('servers', 'last_conntrack_max')) {
                $table->unsignedInteger('last_conntrack_max')->nullable()->after('last_conntrack');
            }
            if (! Schema::hasColumn('servers', 'metrics_sampled_at')) {
                $table->timestamp('metrics_sampled_at')->nullable()->after('last_conntrack_max');
            }
        });

        // ---------------------------------------------------------------
        // Tunnel groups: N agents of one kind between one Iran server and a
        // set of foreign exits. The unit of apply/reconcile/rollback.
        // ---------------------------------------------------------------
        Schema::create('tunnel_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // l2tpv2 | l2tpv3_ip | l2tpv3_udp | gre | gre6 | ipip | eoip | vxlan
            $table->string('kind', 16);
            $table->foreignId('iran_server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedTinyInteger('agents_per_exit')->default(2);
            $table->string('circuit_id', 32)->nullable();
            // normal: Iran dials out; reverse: foreign dials in.
            $table->string('direction', 8)->default('normal');
            // pcc | ecmp
            $table->string('balancing_mode', 8)->default('pcc');
            $table->boolean('ipsec_enabled')->default(true);
            $table->text('ipsec_secret_enc')->nullable();
            // MTU management: theoretical (overhead table) + probed (DF ping).
            $table->unsignedSmallInteger('mtu_calculated')->nullable();
            $table->unsignedSmallInteger('mtu_probed')->nullable();
            $table->boolean('mss_clamp')->default(true);
            // DPI evasion toggles.
            $table->boolean('auto_switch_l2tp')->default(false);
            $table->boolean('auto_switch_kind')->default(false);
            $table->string('port_hop_list')->nullable();
            // draft | applying | active | degraded | down | removing | error
            $table->string('status', 16)->default('draft');
            $table->text('status_message')->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
            $table->index(['iran_server_id', 'status']);
        });

        Schema::create('tunnel_group_exits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tunnel_group_id')->constrained('tunnel_groups')->cascadeOnDelete();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            // pending | active | draining | removed
            $table->string('status', 16)->default('pending');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tunnel_group_id', 'server_id']);
        });

        // ---------------------------------------------------------------
        // Tunnel agents: one real tunnel interface pair. weight feeds the
        // load balancer; quality_score and baseline detect throttling.
        // ---------------------------------------------------------------
        Schema::create('tunnel_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tunnel_group_id')->constrained('tunnel_groups')->cascadeOnDelete();
            $table->foreignId('tunnel_group_exit_id')->constrained('tunnel_group_exits')->cascadeOnDelete();
            $table->unsignedSmallInteger('seq')->default(1);
            // Current effective kind (may differ from group kind after an
            // automatic DPI switch, e.g. l2tpv3_udp -> l2tpv2).
            $table->string('kind', 16);
            $table->string('iran_interface', 64);
            $table->string('foreign_interface', 64);
            $table->string('transport_network', 32);
            $table->string('iran_ip', 45);
            $table->string('foreign_ip', 45);
            $table->unsignedInteger('tunnel_id_value')->nullable();
            $table->unsignedInteger('udp_port')->nullable();
            $table->unsignedSmallInteger('weight')->default(1);
            $table->boolean('is_enabled')->default(true);
            // up | down | degraded | unknown
            $table->string('health', 12)->default('unknown');
            $table->decimal('quality_score', 5, 2)->nullable();
            $table->unsignedBigInteger('baseline_rx_bps')->nullable();
            $table->unsignedBigInteger('baseline_tx_bps')->nullable();
            $table->unsignedSmallInteger('fail_count')->default(0);
            $table->timestamp('last_seen_up_at')->nullable();
            $table->timestamp('last_switched_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tunnel_group_exit_id', 'seq']);
            $table->index(['tunnel_group_id', 'health']);
        });

        // ---------------------------------------------------------------
        // Managed client-facing interfaces (wg-public / ppp-public / wg-tr…)
        // with a dedicated subnet from an IP pool. Account peers attach by
        // interface name only — account logic stays untouched.
        // ---------------------------------------------------------------
        Schema::create('managed_interfaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('tunnel_group_id')->nullable()->constrained('tunnel_groups')->nullOnDelete();
            // wireguard | ppp
            $table->string('type', 12);
            $table->string('name', 64);
            $table->string('subnet', 32);
            $table->unsignedInteger('listen_port')->nullable();
            $table->string('public_key')->nullable();
            $table->text('private_key_enc')->nullable();
            // pending | active | error | removing
            $table->string('status', 16)->default('pending');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'name']);
        });

        // ---------------------------------------------------------------
        // Desired network objects: every router row the panel owns, found on
        // the router via a unique marker comment (vpnl:<type>:<id>).
        // Covers routes, ip addresses, mangle, NAT, filter, routing rules…
        // ---------------------------------------------------------------
        Schema::create('desired_network_objects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('tunnel_group_id')->nullable()->constrained('tunnel_groups')->cascadeOnDelete();
            $table->foreignId('managed_interface_id')->nullable()->constrained('managed_interfaces')->cascadeOnDelete();
            // route | ip_address | mangle | nat | filter | routing_rule |
            // interface | wireguard | ipsec | routing_table | script | scheduler
            $table->string('object_type', 24);
            // RouterOS menu path, e.g. /ip/route
            $table->string('menu', 64);
            // Unique marker stored in the RouterOS comment field.
            $table->string('marker', 96);
            // Full desired payload (RouterOS attributes).
            $table->json('payload');
            // pending | applied | drift | error | removing
            $table->string('status', 12)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'marker']);
            $table->index(['server_id', 'object_type']);
            $table->index(['tunnel_group_id']);
            $table->index(['status']);
        });

        // ---------------------------------------------------------------
        // Metrics: raw agent samples (prune > 48h) + hourly rollups (30-90d).
        // ---------------------------------------------------------------
        Schema::create('tunnel_metric_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tunnel_agent_id')->constrained('tunnel_agents')->cascadeOnDelete();
            $table->timestamp('sampled_at');
            $table->boolean('up')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('jitter_ms')->nullable();
            $table->decimal('loss_pct', 5, 2)->nullable();
            $table->unsignedBigInteger('rx_bps')->default(0);
            $table->unsignedBigInteger('tx_bps')->default(0);
            $table->decimal('score', 5, 2)->nullable();

            $table->index(['tunnel_agent_id', 'sampled_at']);
            $table->index(['sampled_at']);
        });

        Schema::create('tunnel_metric_rollups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tunnel_agent_id')->constrained('tunnel_agents')->cascadeOnDelete();
            $table->timestamp('bucket_at');
            $table->unsignedInteger('samples')->default(0);
            $table->unsignedInteger('up_samples')->default(0);
            $table->unsignedInteger('latency_avg_ms')->nullable();
            $table->unsignedInteger('latency_max_ms')->nullable();
            $table->unsignedInteger('jitter_avg_ms')->nullable();
            $table->decimal('loss_avg_pct', 5, 2)->nullable();
            $table->unsignedBigInteger('rx_bps_avg')->default(0);
            $table->unsignedBigInteger('tx_bps_avg')->default(0);
            $table->unsignedBigInteger('rx_bps_max')->default(0);
            $table->unsignedBigInteger('tx_bps_max')->default(0);
            $table->decimal('score_avg', 5, 2)->nullable();

            $table->unique(['tunnel_agent_id', 'bucket_at']);
            $table->index(['bucket_at']);
        });

        Schema::create('server_metric_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->timestamp('sampled_at');
            $table->decimal('cpu_pct', 5, 2)->nullable();
            $table->decimal('ram_pct', 5, 2)->nullable();
            $table->unsignedInteger('conntrack')->nullable();
            $table->unsignedInteger('conntrack_max')->nullable();
            $table->unsignedBigInteger('rx_bps')->default(0);
            $table->unsignedBigInteger('tx_bps')->default(0);
            $table->json('per_core')->nullable();

            $table->index(['server_id', 'sampled_at']);
            $table->index(['sampled_at']);
        });

        // ---------------------------------------------------------------
        // Versioned desired-state snapshots per group — rollback = re-apply
        // the previous version.
        // ---------------------------------------------------------------
        Schema::create('config_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tunnel_group_id')->constrained('tunnel_groups')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('checksum', 64);
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['tunnel_group_id', 'version']);
        });

        // ---------------------------------------------------------------
        // On-router monitoring scripts (scheduler probe -> POST to panel).
        // ---------------------------------------------------------------
        Schema::create('router_scripts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('name', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('checksum', 64);
            $table->string('report_token', 64);
            // pending | installed | outdated | error
            $table->string('status', 16)->default('pending');
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('last_report_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'name']);
        });

        // ---------------------------------------------------------------
        // IP pools: collision-free subnets for tunnel transports (/30) and
        // client-facing interface subnets (/24 per location).
        // ---------------------------------------------------------------
        Schema::create('ip_pools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('cidr', 32);
            // transport | wg_clients | ppp_clients
            $table->string('purpose', 16);
            $table->unsignedTinyInteger('allocation_prefix')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['cidr']);
        });

        Schema::create('ip_pool_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ip_pool_id')->constrained('ip_pools')->cascadeOnDelete();
            $table->string('cidr', 32);
            $table->string('owner_type', 64)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();

            $table->unique(['ip_pool_id', 'cidr']);
            $table->index(['owner_type', 'owner_id']);
        });

        // ---------------------------------------------------------------
        // Tunnel events: human-readable timeline (apply, failover, switch,
        // drift repair, alert) shown in the UI.
        // ---------------------------------------------------------------
        Schema::create('tunnel_group_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tunnel_group_id')->nullable()->constrained('tunnel_groups')->cascadeOnDelete();
            $table->foreignId('tunnel_agent_id')->nullable()->constrained('tunnel_agents')->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            // ok | warning | error
            $table->string('level', 8)->default('ok');
            $table->text('message')->nullable();
            $table->json('detail')->nullable();
            $table->timestamps();

            $table->index(['tunnel_group_id', 'created_at']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'tunnel_group_events',
            'ip_pool_allocations',
            'ip_pools',
            'router_scripts',
            'config_versions',
            'server_metric_samples',
            'tunnel_metric_rollups',
            'tunnel_metric_samples',
            'desired_network_objects',
            'managed_interfaces',
            'tunnel_agents',
            'tunnel_group_exits',
            'tunnel_groups',
            'locations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        Schema::table('servers', function (Blueprint $table): void {
            foreach (['account_cap', 'last_cpu_pct', 'last_throughput_bps', 'last_conntrack', 'last_conntrack_max', 'metrics_sampled_at'] as $column) {
                if (Schema::hasColumn('servers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
