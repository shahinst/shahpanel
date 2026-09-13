<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedInterface extends Model
{
    protected $fillable = [
        'server_id',
        'location_id',
        'tunnel_group_id',
        'type',
        'name',
        'subnet',
        'listen_port',
        'public_key',
        'private_key_enc',
        'status',
        'meta',
    ];

    protected $hidden = ['private_key_enc'];

    protected function casts(): array
    {
        return [
            'listen_port' => 'integer',
            'private_key_enc' => 'encrypted',
            'meta' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function tunnelGroup(): BelongsTo
    {
        return $this->belongsTo(TunnelGroup::class);
    }

    /** Plain WireGuard private key for RouterOS API (decrypted). */
    public function wireguardPrivateKey(): string
    {
        $value = $this->private_key_enc;

        return is_string($value) ? $value : '';
    }

    /** Gateway IP (first host) of the interface subnet, CIDR-suffixed. */
    public function gatewayCidr(): string
    {
        [$base, $prefix] = array_pad(explode('/', $this->subnet, 2), 2, '24');
        $network = ip2long($base) & (~((1 << (32 - (int) $prefix)) - 1) & 0xFFFFFFFF);

        return long2ip($network + 1).'/'.$prefix;
    }
}
