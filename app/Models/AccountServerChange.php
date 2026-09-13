<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountServerChange extends Model
{
    protected $fillable = [
        'account_id',
        'changed_by_user_id',
        'old_server_id',
        'new_server_id',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function oldServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'old_server_id');
    }

    public function newServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'new_server_id');
    }
}
