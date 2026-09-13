<?php

namespace App\Models;

use App\Enums\CrmTunnelLogAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmTunnelLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'crm_tunnel_id',
        'action',
        'server_id',
        'success',
        'output',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => CrmTunnelLogAction::class,
            'success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function tunnel(): BelongsTo
    {
        return $this->belongsTo(CrmTunnel::class, 'crm_tunnel_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
