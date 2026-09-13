<?php

namespace App\Models;

use App\Enums\SyncLogStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerSyncLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'server_id',
        'started_at',
        'finished_at',
        'accounts_synced',
        'errors_count',
        'error_details',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'accounts_synced' => 'integer',
            'errors_count' => 'integer',
            'status' => SyncLogStatus::class,
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
