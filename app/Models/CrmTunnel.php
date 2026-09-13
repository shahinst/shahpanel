<?php

namespace App\Models;

use App\Enums\CrmTunnelStatus;
use App\Enums\CrmTunnelType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmTunnel extends Model
{
    protected $fillable = [
        'type',
        'hub_server_id',
        'exit_server_id',
        'name',
        'tunnel_subnet',
        'hub_tunnel_ip',
        'exit_tunnel_ip',
        'keepalive',
        'comment_tag',
        'status',
        'last_tested_at',
        'last_result',
    ];

    protected function casts(): array
    {
        return [
            'type' => CrmTunnelType::class,
            'status' => CrmTunnelStatus::class,
            'last_tested_at' => 'datetime',
            'last_result' => 'array',
        ];
    }

    public function hubServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'hub_server_id');
    }

    public function exitServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'exit_server_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CrmTunnelLog::class)->latest('created_at');
    }

    public function systemCommentPrefix(): string
    {
        return (string) config('crm_tunnel.comment_prefix', 'CRM-TUN');
    }
}
