<?php

namespace App\Models;

use App\Enums\BalancingMode;
use App\Enums\TunnelDirection;
use App\Enums\TunnelGroupStatus;
use App\Enums\TunnelKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TunnelGroup extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'kind',
        'iran_server_id',
        'location_id',
        'agents_per_exit',
        'circuit_id',
        'direction',
        'balancing_mode',
        'ipsec_enabled',
        'ipsec_secret_enc',
        'mtu_calculated',
        'mtu_probed',
        'mss_clamp',
        'auto_switch_l2tp',
        'auto_switch_kind',
        'port_hop_list',
        'status',
        'status_message',
        'last_applied_at',
        'last_reconciled_at',
        'meta',
    ];

    protected $hidden = ['ipsec_secret_enc'];

    protected function casts(): array
    {
        return [
            'kind' => TunnelKind::class,
            'direction' => TunnelDirection::class,
            'balancing_mode' => BalancingMode::class,
            'status' => TunnelGroupStatus::class,
            'agents_per_exit' => 'integer',
            'ipsec_enabled' => 'boolean',
            'ipsec_secret_enc' => 'encrypted',
            'mtu_calculated' => 'integer',
            'mtu_probed' => 'integer',
            'mss_clamp' => 'boolean',
            'auto_switch_l2tp' => 'boolean',
            'auto_switch_kind' => 'boolean',
            'last_applied_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function iranServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'iran_server_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function exits(): HasMany
    {
        return $this->hasMany(TunnelGroupExit::class)->orderBy('position');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(TunnelAgent::class);
    }

    public function desiredObjects(): HasMany
    {
        return $this->hasMany(DesiredNetworkObject::class);
    }

    public function configVersions(): HasMany
    {
        return $this->hasMany(ConfigVersion::class)->orderByDesc('version');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TunnelGroupEvent::class)->latest();
    }

    /** Effective MTU: probed wins over calculated. */
    public function effectiveMtu(): ?int
    {
        return $this->mtu_probed ?? $this->mtu_calculated;
    }

    /** @return list<int> */
    public function portHopPorts(): array
    {
        return collect(explode(',', (string) $this->port_hop_list))
            ->map(fn (string $port): int => (int) trim($port))
            ->filter(fn (int $port): bool => $port > 0 && $port <= 65535)
            ->values()
            ->all();
    }

    public function isReverse(): bool
    {
        return $this->direction === TunnelDirection::Reverse;
    }

    /**
     * Tunnel kinds to spread across agents (round-robin by seq).
     *
     * @return list<TunnelKind>
     */
    public function kindMix(): array
    {
        $raw = $this->meta['kind_mix'] ?? null;

        if (is_array($raw) && $raw !== []) {
            return array_values(array_map(
                static fn (string $value): TunnelKind => TunnelKind::from($value),
                $raw,
            ));
        }

        return [$this->kind];
    }

    public function kindForAgentSeq(int $seq): TunnelKind
    {
        $mix = $this->kindMix();

        return $mix[($seq - 1) % count($mix)];
    }

    /** @return list<string> */
    public function kindMixLabels(): array
    {
        return array_map(static fn (TunnelKind $k): string => $k->label(), $this->kindMix());
    }

    /**
     * Client-facing service types to provision on the Iran server for this
     * group (used by TunnelGroupOrchestrator::ensureClientInfrastructure()).
     *
     * @return list<string> subset of ['wireguard', 'ppp']
     */
    public function clientServiceTypes(): array
    {
        $raw = $this->meta['client_service_types'] ?? null;

        if (is_array($raw) && $raw !== []) {
            return array_values(array_intersect(array_unique($raw), ['wireguard', 'ppp']));
        }

        return ['wireguard'];
    }

    /**
     * How multiple selected tunnel kinds (kindMix) behave together:
     * - single:   only one kind selected (default; unchanged legacy behavior)
     * - balanced: round-robin across agents + load balancer (pcc/ecmp/range_split)
     * - priority: only the highest-priority kind is active; others are
     *             provisioned as disabled standby agents, auto-promoted on failure.
     */
    public function kindSelectionMode(): string
    {
        $mode = $this->meta['kind_selection_mode'] ?? null;

        if (in_array($mode, ['single', 'balanced', 'priority'], true)) {
            return $mode;
        }

        return count($this->kindMix()) > 1 ? 'balanced' : 'single';
    }

    /**
     * Ordered tunnel kinds for priority (active/passive) mode — first is primary.
     *
     * @return list<TunnelKind>
     */
    public function priorityKindOrder(): array
    {
        $raw = $this->meta['kind_priority_order'] ?? null;

        if (is_array($raw) && $raw !== []) {
            return array_values(array_map(
                static fn (string $value): TunnelKind => TunnelKind::from($value),
                $raw,
            ));
        }

        return $this->kindMix();
    }

    /** Currently active kind in priority (active/passive) mode. */
    public function activeKind(): TunnelKind
    {
        $raw = $this->meta['active_kind'] ?? null;

        if (is_string($raw) && $raw !== '') {
            try {
                return TunnelKind::from($raw);
            } catch (\ValueError) {
                // fall through to default below
            }
        }

        $order = $this->priorityKindOrder();

        return $order[0] ?? $this->kind;
    }
}
