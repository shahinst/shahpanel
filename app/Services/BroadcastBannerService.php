<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\NotificationBroadcast;
use App\Models\PanelNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class BroadcastBannerService
{
    /**
     * @return array{featured: ?array<string, mixed>, history: array<int, array<string, mixed>>}|null
     */
    public function forUser(User $user): ?array
    {
        if (! in_array($user->role, [UserRole::Agent, UserRole::Seller], true)) {
            return null;
        }

        $watermark = (int) ($user->broadcast_banner_watermark_id ?? 0);

        $featuredNotification = PanelNotification::query()
            ->where('user_id', $user->id)
            ->where('type', NotificationType::Broadcast)
            ->when($watermark > 0, fn ($query) => $query->where('id', '>', $watermark))
            ->orderByDesc('id')
            ->first();

        if ($featuredNotification === null) {
            return null;
        }

        $allNotifications = PanelNotification::query()
            ->where('user_id', $user->id)
            ->where('type', NotificationType::Broadcast)
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $broadcasts = $this->loadBroadcasts($allNotifications);
        $featured = $this->mapItem($featuredNotification, $broadcasts);

        $history = $allNotifications
            ->reject(fn (PanelNotification $notification): bool => (int) $notification->id === (int) $featuredNotification->id)
            ->map(fn (PanelNotification $notification): array => $this->mapItem($notification, $broadcasts))
            ->values()
            ->all();

        return [
            'featured' => $featured,
            'history' => $history,
        ];
    }

    public function dismiss(User $user, int $notificationId): void
    {
        $notification = PanelNotification::query()
            ->where('user_id', $user->id)
            ->where('type', NotificationType::Broadcast)
            ->findOrFail($notificationId);

        $user->update([
            'broadcast_banner_watermark_id' => max(
                (int) ($user->broadcast_banner_watermark_id ?? 0),
                (int) $notification->id,
            ),
        ]);
    }

    /**
     * @param  Collection<int, PanelNotification>  $notifications
     * @return Collection<int, NotificationBroadcast>
     */
    protected function loadBroadcasts(Collection $notifications): Collection
    {
        $ids = $notifications
            ->map(fn (PanelNotification $notification): ?int => $this->broadcastIdFrom($notification))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return NotificationBroadcast::query()
            ->with('sender')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, NotificationBroadcast>  $broadcasts
     * @return array<string, mixed>
     */
    protected function mapItem(PanelNotification $notification, Collection $broadcasts): array
    {
        $broadcastId = $this->broadcastIdFrom($notification);
        $broadcast = $broadcastId ? $broadcasts->get($broadcastId) : null;

        return [
            'notification_id' => $notification->id,
            'broadcast_id' => $broadcastId,
            'title' => $notification->title,
            'body' => $notification->body,
            'link' => $notification->link,
            'image_url' => $this->imageUrl($broadcast?->image_path),
            'created_at' => $notification->created_at,
            'sender_name' => $broadcast?->sender?->full_name ?: $broadcast?->sender?->username,
        ];
    }

    protected function broadcastIdFrom(PanelNotification $notification): ?int
    {
        if (! $notification->reference_key || ! str_starts_with($notification->reference_key, 'broadcast:')) {
            return null;
        }

        $id = (int) substr($notification->reference_key, strlen('broadcast:'));

        return $id > 0 ? $id : null;
    }

    protected function imageUrl(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
