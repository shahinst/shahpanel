<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPackageDurationPrice extends Model
{
    protected $fillable = [
        'user_id',
        'package_duration_id',
        'wholesale_price',
        'assigned_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'wholesale_price' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function packageDuration(): BelongsTo
    {
        return $this->belongsTo(PackageDuration::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
