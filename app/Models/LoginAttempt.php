<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = ['ip', 'username', 'succeeded', 'user_agent', 'path', 'created_at'];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean', 'created_at' => 'datetime'];
    }
}
