<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TunnelGroupEvent extends Model
{
    protected $fillable = [
        'tunnel_group_id',
        'tunnel_agent_id',
        'server_id',
        'user_id',
        'action',
        'level',
        'message',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TunnelGroup::class, 'tunnel_group_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(TunnelAgent::class, 'tunnel_agent_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function record(string $action, string $message, array $attributes = [], string $level = 'ok'): self
    {
        return self::create(array_merge([
            'action' => $action,
            'level' => $level,
            'message' => $message,
            'user_id' => auth()->id(),
        ], $attributes));
    }
}
