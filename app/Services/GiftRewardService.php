<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\PushGiftAccountExpiryJob;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Admin «هدایا»: افزودن روز به انقضای اکانت‌ها و شارژ کیف پول نمایندگان/فروشندگان.
 */
class GiftRewardService
{
    public function __construct(
        protected AccountService $accountService,
        protected WalletAdjustmentService $walletAdjustmentService,
        protected ActivityLogService $activityLogService,
        protected GiftAccountService $giftAccountService,
    ) {}

    /**
     * @param  list<int>  $agentIds
     */
    public function sellersForAgents(array $agentIds): Collection
    {
        return $this->giftAccountService->sellersForAgents($agentIds);
    }

    /**
     * @param  list<int>  $agentIds
     * @param  list<int>  $sellerIds
     * @return Collection<int, User>
     */
    public function resolveRecipients(array $agentIds, array $sellerIds): Collection
    {
        $ids = collect($agentIds)
            ->merge($sellerIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            throw new InvalidArgumentException(__('gifts.recipients_required'));
        }

        $recipients = User::query()
            ->whereIn('id', $ids)
            ->whereIn('role', [UserRole::Agent, UserRole::Seller])
            ->get();

        if ($recipients->isEmpty()) {
            throw new InvalidArgumentException(__('gifts.invalid_recipients'));
        }

        return $recipients;
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @return list<int>
     */
    protected function accountOwnerIds(Collection $recipients): array
    {
        $agentIds = $recipients
            ->filter(fn (User $user): bool => $user->role === UserRole::Agent)
            ->pluck('id')
            ->all();

        $sellerIds = $recipients
            ->filter(fn (User $user): bool => $user->role === UserRole::Seller)
            ->pluck('id')
            ->all();

        if ($agentIds !== []) {
            $childSellerIds = User::query()
                ->where('role', UserRole::Seller)
                ->whereIn('parent_id', $agentIds)
                ->pluck('id')
                ->all();
            $sellerIds = array_values(array_unique(array_merge($sellerIds, $childSellerIds)));
        }

        return array_values(array_unique(array_merge($agentIds, $sellerIds)));
    }

    /**
     * @param  list<int>  $agentIds
     * @param  list<int>  $sellerIds
     * @return array{recipients: int, updated: int, skipped_unlimited: int, failed: list<string>, remote_queued: int}
     */
    public function extendAccountDays(
        User $admin,
        array $agentIds,
        array $sellerIds,
        int $days,
        ?string $note = null,
    ): array {
        if ($days < 1 || $days > 3650) {
            throw new InvalidArgumentException(__('gifts.days_invalid'));
        }

        $recipients = $this->resolveRecipients($agentIds, $sellerIds);
        $ownerIds = $this->accountOwnerIds($recipients);

        $accounts = Account::query()
            ->whereIn('owner_seller_id', $ownerIds)
            ->orderBy('id')
            ->get(['id', 'owner_seller_id', 'server_id', 'expiry_at', 'status', 'display_label', 'remote_username']);

        $updated = 0;
        $skippedUnlimited = 0;
        $failed = [];
        $remoteAccountIds = [];
        $now = now();

        DB::transaction(function () use ($accounts, $days, $now, &$updated, &$skippedUnlimited, &$failed, &$remoteAccountIds): void {
            foreach ($accounts as $account) {
                if ($account->expiry_at === null) {
                    $skippedUnlimited++;
                    continue;
                }

                try {
                    $base = $account->expiry_at->isFuture()
                        ? $account->expiry_at->copy()
                        : $now->copy();
                    $newExpiry = $base->addDays($days)->endOfDay();

                    $payload = [
                        'expiry_at' => $newExpiry,
                        'updated_at' => $now,
                    ];

                    if ($account->status === AccountStatus::Expired && $newExpiry->isFuture()) {
                        $payload['status'] = AccountStatus::Active->value;
                    }

                    Account::query()->whereKey($account->id)->update($payload);
                    $updated++;

                    if ($account->server_id !== null) {
                        $remoteAccountIds[] = (int) $account->id;
                    }
                } catch (Throwable $exception) {
                    $label = filled($account->display_label)
                        ? (string) $account->display_label
                        : (string) $account->remote_username;
                    $failed[] = "#{$account->id} {$label}: {$exception->getMessage()}";
                    Log::warning('Gift reward: extend account days failed', [
                        'account_id' => $account->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        });

        $remoteAccountIds = array_values(array_unique($remoteAccountIds));
        foreach (array_chunk($remoteAccountIds, 25) as $chunk) {
            PushGiftAccountExpiryJob::dispatch($chunk);
        }

        $this->activityLogService->log($admin, 'gift.days_granted', null, [
            'days' => $days,
            'recipient_ids' => $recipients->pluck('id')->all(),
            'owner_ids' => $ownerIds,
            'recipients' => $recipients->count(),
            'accounts_updated' => $updated,
            'skipped_unlimited' => $skippedUnlimited,
            'failed' => count($failed),
            'remote_queued' => count($remoteAccountIds),
            'note' => $note,
        ]);

        return [
            'recipients' => $recipients->count(),
            'updated' => $updated,
            'skipped_unlimited' => $skippedUnlimited,
            'failed' => $failed,
            'remote_queued' => count($remoteAccountIds),
        ];
    }

    /**
     * @param  list<int>  $agentIds
     * @param  list<int>  $sellerIds
     * @return array{recipients: int, credited: int, failed: list<string>}
     */
    public function creditWallets(
        User $admin,
        array $agentIds,
        array $sellerIds,
        string $amount,
        ?string $note = null,
    ): array {
        $amount = money_string($amount);
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException(__('gifts.amount_invalid'));
        }

        $recipients = $this->resolveRecipients($agentIds, $sellerIds);

        $result = $this->walletAdjustmentService->creditUsers(
            $admin,
            $recipients,
            $amount,
            $note ?: __('gifts.wallet_note_default'),
        );

        $this->activityLogService->log($admin, 'gift.wallet_credited', null, [
            'amount' => $amount,
            'recipient_ids' => $recipients->pluck('id')->all(),
            'recipients' => $recipients->count(),
            'credited' => $result['credited'],
            'failed' => count($result['failed']),
            'note' => $note,
        ]);

        return [
            'recipients' => $recipients->count(),
            'credited' => $result['credited'],
            'failed' => $result['failed'],
        ];
    }

    /**
     * @return Collection<int, User>
     */
    public function activeAgents(): Collection
    {
        return User::query()
            ->where('role', UserRole::Agent)
            ->where('status', UserStatus::Active)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'username']);
    }

    /**
     * @return Collection<int, User>
     */
    public function activeSellers(): Collection
    {
        return User::query()
            ->where('role', UserRole::Seller)
            ->where('status', UserStatus::Active)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'username', 'parent_id']);
    }
}