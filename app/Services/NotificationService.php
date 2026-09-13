<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\PanelNotification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public function notify(
        User $user,
        NotificationType $type,
        string $title,
        string $body,
        ?string $link = null,
        ?string $referenceKey = null,
    ): PanelNotification {
        $notification = PanelNotification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'reference_key' => $referenceKey,
            'is_read' => false,
            'created_at' => now(),
        ]);

        if ($user->email && config('mail.default') !== 'log') {
            try {
                Mail::raw($body, function ($message) use ($user, $title): void {
                    $message->to($user->email)->subject($title);
                });
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $notification;
    }

    public function markRead(PanelNotification $notification): void
    {
        $notification->update(['is_read' => true]);
    }

    public function markAllRead(User $user): void
    {
        PanelNotification::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }
}
