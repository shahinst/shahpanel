<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\PanelNotification;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Deduped panel alerts with deep links to the relevant account list.
 */
class PanelAlertService
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    public function notifyAccountAlert(
        User $recipient,
        NotificationType $type,
        string $title,
        string $body,
        Account $account,
        string $referenceKey,
        int $cooldownHours = 24,
    ): ?PanelNotification {
        return $this->notifyOnce(
            $recipient,
            $type,
            $title,
            $body,
            $referenceKey,
            $this->accountLinkFor($recipient, $account),
            $cooldownHours,
        );
    }

    public function notifyOnce(
        User $recipient,
        NotificationType $type,
        string $title,
        string $body,
        string $referenceKey,
        ?string $link = null,
        int $cooldownHours = 24,
    ): ?PanelNotification {
        if ($this->recentlyNotified($recipient->id, $type, $referenceKey, $cooldownHours)) {
            return null;
        }

        return $this->notifications->notify(
            $recipient,
            $type,
            $title,
            $body,
            $link,
            $referenceKey,
        );
    }

    public function accountLinkFor(User $recipient, Account $account): ?string
    {
        $account->loadMissing('ownerSeller');
        $panel = match ($recipient->role) {
            UserRole::Admin => 'admin',
            UserRole::Agent => 'agent',
            UserRole::Seller => 'seller',
            default => null,
        };

        if ($panel === null) {
            return null;
        }

        if ($recipient->role === UserRole::Seller && (int) $account->owner_seller_id !== (int) $recipient->id) {
            return null;
        }

        if ($recipient->role === UserRole::Admin && \Illuminate\Support\Facades\Route::has('admin.accounts.edit')) {
            return route('admin.accounts.edit', $account);
        }

        $category = $account->service_type->accountCategory()->value;
        $routeName = "{$panel}.accounts.{$category}";

        if (! \Illuminate\Support\Facades\Route::has($routeName)) {
            return null;
        }

        return route($routeName, ['search' => $account->remote_username]);
    }

    protected function recentlyNotified(int $userId, NotificationType $type, string $referenceKey, int $cooldownHours): bool
    {
        if (! Schema::hasColumn('notifications', 'reference_key')) {
            return false;
        }

        return PanelNotification::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('reference_key', $referenceKey)
            ->where('created_at', '>=', now()->subHours($cooldownHours))
            ->exists();
    }
}
