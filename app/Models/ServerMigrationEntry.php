<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMigrationEntry extends Model
{
    protected $fillable = [
        'server_migration_id',
        'account_id',
        'remote_username',
        'status',
        'message',
        'subscription_url',
        'error',
        'data_limit_bytes',
        'data_used_bytes',
        'expiry_at',
    ];

    protected function casts(): array
    {
        return [
            'data_limit_bytes' => 'integer',
            'data_used_bytes' => 'integer',
            'expiry_at' => 'datetime',
        ];
    }

    public function migration(): BelongsTo
    {
        return $this->belongsTo(ServerMigration::class, 'server_migration_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
