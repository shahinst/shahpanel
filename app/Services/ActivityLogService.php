<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class ActivityLogService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function log(?User $user, string $action, ?Model $entity = null, array $payload = []): ActivityLog
    {
        return ActivityLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entity !== null ? $entity->getMorphClass() : null,
            'entity_id' => $entity?->getKey(),
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }
}
