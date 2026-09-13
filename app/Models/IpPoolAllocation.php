<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IpPoolAllocation extends Model
{
    protected $fillable = [
        'ip_pool_id',
        'cidr',
        'owner_type',
        'owner_id',
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(IpPool::class, 'ip_pool_id');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
