<?php

namespace Modules\Dedicated\Models;

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A server that belongs to one dedicated agent, and how its usage is metered. */
class DedicatedServer extends Model
{
    protected $table = 'dedicated_servers';

    protected $fillable = ['agent_user_id', 'server_id', 'meter_interface', 'last_counter', 'total_rx_bytes', 'last_read_at', 'last_error'];

    protected function casts(): array
    {
        return [
            'last_counter' => 'integer',
            'total_rx_bytes' => 'integer',
            'last_read_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return list<int> */
    public static function serverIdsOf(User $agent): array
    {
        return static::query()->where('agent_user_id', $agent->id)->pluck('server_id')->map(fn ($id): int => (int) $id)->all();
    }

    public static function isDedicatedAgent(?User $user): bool
    {
        return $user !== null && static::query()->where('agent_user_id', $user->id)->exists();
    }
}
