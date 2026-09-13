<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_server_id',
        'to_server_id',
        'status',
        'total_accounts',
        'migrated_count',
        'failed_count',
        'skipped_count',
        'account_ids',
        'dry_run',
        'summary',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'account_ids' => 'array',
            'dry_run' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'from_server_id');
    }

    public function toServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'to_server_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ServerMigrationEntry::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }
}
