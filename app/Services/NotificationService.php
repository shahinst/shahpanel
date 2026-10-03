<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\PanelNotification;
use App\Models\Setting;
use App\Models\User;
use App\Support\ServerBackupTelegramSettings;
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

        $this->sendToTelegram($user, $title, $body, $link);

        return $notification;
    }

    /**
     * Users who put a numeric Telegram id in their profile (and started the
     * panel bot) also get the notification there, when the admin turned it on.
     */
    protected function sendToTelegram(User $user, string $title, string $body, ?string $link): void
    {
        $chatId = trim((string) $user->telegram_id);

        if (preg_match('/^-?\d{5,20}$/', $chatId) !== 1
            || Setting::getValue('alert_telegram_enabled', '0') !== '1'
            || ServerBackupTelegramSettings::botToken() === null) {
            return;
        }

        $text = '<b>'.e($title).'</b>'."\n".e($body);

        if ($link !== null && $link !== '') {
            $text .= "\n".e($link);
        }

        try {
            SendTelegramNotificationJob::dispatch($chatId, $text);
        } catch (\Throwable $exception) {
            report($exception);
        }
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
