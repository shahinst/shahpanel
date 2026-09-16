<?php

namespace App\Services;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastStatus;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\NotificationBroadcast;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BroadcastNotificationService
{
    public function __construct(
        protected NotificationService $notificationService,
    ) {}

    public function sendAsAdmin(
        User $admin,
        BroadcastAudience $audience,
        string $title,
        string $body,
        ?string $link = null,
        ?array $recipientUserIds = null,
    ): NotificationBroadcast {
        $broadcast = NotificationBroadcast::query()->create([
            'sender_user_id' => $admin->id,
            'audience' => $audience,
            'recipient_user_ids' => $recipientUserIds,
            'status' => BroadcastStatus::Approved,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
        ]);

        return $broadcast;
    }

    public function submitAsAgent(User $agent, string $title, string $body, ?string $link = null): NotificationBroadcast
    {
        return NotificationBroadcast::query()->create([
            'sender_user_id' => $agent->id,
            'audience' => BroadcastAudience::AgentSellers,
            'status' => BroadcastStatus::Pending,
            'title' => $title,
            'body' => $body,
            'link' => $link,
        ]);
    }

    public function approve(NotificationBroadcast $broadcast, User $admin, ?string $note = null): NotificationBroadcast
    {
        if ($broadcast->status !== BroadcastStatus::Pending) {
            throw new InvalidArgumentException(__('services.broadcast_request_already_reviewed'));
        }

        $broadcast->update([
            'status' => BroadcastStatus::Approved,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
            'admin_note' => $note,
        ]);

        $this->dispatch($broadcast->fresh());

        return $broadcast->fresh();
    }

    public function reject(NotificationBroadcast $broadcast, User $admin, ?string $note = null): NotificationBroadcast
    {
        if ($broadcast->status !== BroadcastStatus::Pending) {
            throw new InvalidArgumentException(__('services.broadcast_request_already_reviewed'));
        }

        $broadcast->update([
            'status' => BroadcastStatus::Rejected,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
            'admin_note' => $note,
        ]);

        return $broadcast->fresh();
    }

    public function dispatch(NotificationBroadcast $broadcast): int
    {
        $recipients = $this->resolveRecipients($broadcast);
        $count = 0;

        foreach ($recipients as $user) {
            $this->notificationService->notify(
                $user,
                NotificationType::Broadcast,
                $broadcast->title,
                $broadcast->body,
                $broadcast->link,
                'broadcast:'.$broadcast->id,
            );
            $count++;
        }

        return $count;
    }

    /**
     * @return Collection<int, User>
     */
    protected function resolveRecipients(NotificationBroadcast $broadcast): Collection
    {
        return match ($broadcast->audience) {
            BroadcastAudience::Agents => $this->resolveExplicitOrRoleRecipients(
                $broadcast,
                UserRole::Agent,
            ),
            BroadcastAudience::Sellers => $this->resolveExplicitOrRoleRecipients(
                $broadcast,
                UserRole::Seller,
            ),
            BroadcastAudience::AgentSellers => User::query()
                ->role(UserRole::Seller)
                ->where('parent_id', $broadcast->sender_user_id)
                ->get(),
        };
    }

    /**
     * @return Collection<int, User>
     */
    protected function resolveExplicitOrRoleRecipients(
        NotificationBroadcast $broadcast,
        UserRole $role,
    ): Collection {
        $ids = collect($broadcast->recipient_user_ids ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids !== []) {
            return User::query()
                ->whereIn('id', $ids)
                ->where('role', $role)
                ->get();
        }

        return User::query()->role($role)->get();
    }
}
