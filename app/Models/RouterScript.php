<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterScript extends Model
{
    protected $fillable = [
        'server_id',
        'name',
        'version',
        'checksum',
        'report_token',
        'status',
        'installed_at',
        'last_report_at',
        'last_error',
    ];

    protected $hidden = ['report_token'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'installed_at' => 'datetime',
            'last_report_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
