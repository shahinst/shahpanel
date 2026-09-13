<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'slug',
        'name',
        'version',
        'description',
        'author',
        'provider',
        'status',
        'manifest',
        'installed_at',
        'activated_at',
    ];

    protected $casts = [
        'manifest' => 'array',
        'installed_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
