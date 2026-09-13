<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerInterface extends Model
{
    protected $fillable = [
        'server_id',
        'remote_key',
        'name',
        'category',
        'protocol',
        'port',
        'is_enabled',
        'meta',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'is_enabled' => 'boolean',
            'meta' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function profileTypeLabel(): string
    {
        return match ($this->category) {
            'wireguard' => 'WireGuard',
            'ppp' => 'PPP',
            'inbound' => (string) ($this->protocol ?: 'Inbound'),
            default => $this->category,
        };
    }

    public function isWireguardProfile(): bool
    {
        return $this->category === 'wireguard'
            || str_starts_with((string) $this->remote_key, 'profile:wg:')
            || str_starts_with((string) $this->remote_key, 'wg:');
    }

    public function isPppProfile(): bool
    {
        return $this->category === 'ppp'
            || str_starts_with((string) $this->remote_key, 'profile:ppp:');
    }
}
