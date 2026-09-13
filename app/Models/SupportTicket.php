<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = [
        'ticket_number',
        'subject',
        'status',
        'requester_user_id',
        'assignee_user_id',
        'department_id',
        'source_ticket_id',
        'resolved_at',
        'closed_at',
        'reopened_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(SupportDepartment::class, 'department_id');
    }

    public function sourceTicket(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_ticket_id');
    }

    public function escalatedTicket(): HasMany
    {
        return $this->hasMany(self::class, 'source_ticket_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id');
    }

    public function isClosed(): bool
    {
        return $this->status === TicketStatus::Closed;
    }
}
