<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IpPool extends Model
{
    protected $fillable = [
        'name',
        'cidr',
        'purpose',
        'allocation_prefix',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allocation_prefix' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(IpPoolAllocation::class);
    }
}
