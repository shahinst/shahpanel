<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientDisplayPrice extends Model
{
    protected $fillable = [
        'user_id',
        'package_duration_id',
        'display_price',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'display_price' => 'decimal:2',
            'is_visible' => 'boolean',
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
}
