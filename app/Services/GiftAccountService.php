<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class GiftAccountService
{
    public function __construct(
        protected AccountService $accountService,
        protected PackageService $packageService,
        protected ServerSelectionService $serverSelection,
        protected UserPackageAssignmentService $assignmentService,
        protected ActivityLogService $activityLogService,
    ) {}

    /**
     * @param  list<int>  $recipientIds
     * @return array{
     *     created: list<array{user_id: int, account_id: int, label: string}>,
     *     skipped: list<array{user_id: int, name: string, existing_label: string, message: string}>,
     *     failed: list<array{user_id: int, name: string, error: string}>
     * }
     */
    public function createForRecipients(
        User $admin,
        array $recipientIds,
        Package $package,
        PackageDuration $duration,
        string $charge,
        ?Carbon $expiryAt,
        bool $unlimitedExpiry,
        string $namePrefix,
        ?int $serverId = null,
        ?float $dataGb = null,
    ): array {
        $recipientIds = collect($recipientIds)->map(fn ($id): int => (int) $id)->unique()->values()->all();

        if ($recipientIds === []) {
            throw new InvalidArgumentException(__('gift_accounts.recipients_required'));
        }

        $recipients = User::query()->whereIn('id', $recipientIds)->get();

        if ($recipients->count() !== count($recipientIds)) {
            throw new InvalidArgumentException(__('gift_accounts.invalid_recipients'));
        }

        $duration->setRelation('package', $package);
        $charge = money_string($charge);
        $expiry = $unlimitedExpiry ? null : $expiryAt;
        $prefix = rtrim(trim($namePrefix));

        if ($prefix === '') {
            throw new InvalidArgumentException(__('gift_accounts.name_prefix_required'));
        }

        $server = $this->resolveServer($package, $serverId);

        $created = [];
        $skipped = [];
        $failed = [];

        foreach ($recipients as $recipient) {
            $existingGift = $this->existingGiftForRecipient($recipient);

            if ($existingGift !== null) {
                $existingLabel = filled($existingGift->display_label)
                    ? (string) $existingGift->display_label
                    : (string) $existingGift->remote_username;

                $skipped[] = [
                    'user_id' => (int) $recipient->id,
                    'name' => $recipient->full_name,
                    'existing_label' => $existingLabel,
                    'message' => __('gift_accounts.already_given', [
                        'name' => $recipient->full_name,
                        'label' => $existingLabel,
                    ]),
                ];

                continue;
            }

            try {
                $this->assignmentService->assertUserHasPackage($recipient, $package);

                if ($package->isElastic()) {
                    if ($dataGb === null || $dataGb <= 0) {
                        throw new InvalidArgumentException(__('packages.elastic_gb_required'));
                    }

                    $dataGb = $package->clampDataGb($dataGb);
                }

                $displayLabel = $prefix.$recipient->full_name;

                $clientData = [
                    'skip_portal_client' => true,
                    'display_label' => $displayLabel,
                    'auto_random_remote_identity' => true,
                    'custom_expiry_at' => $expiry,
                ];

                if ($package->isElastic()) {
                    $clientData['data_gb'] = $dataGb;
                }

                $account = $this->accountService->createAccount(
                    $recipient,
                    $package,
                    $server,
                    $duration,
                    $clientData,
                    adminCustomCharge: $charge,
                );

                $this->activityLogService->log($admin, 'account.gift_created', $account, [
                    'recipient_id' => $recipient->id,
                    'recipient_role' => $recipient->role->value,
                    'charge' => $charge,
                    'expiry_at' => $expiry?->toIso8601String(),
                    'display_label' => $displayLabel,
                ]);

                $created[] = [
                    'user_id' => (int) $recipient->id,
                    'account_id' => (int) $account->id,
                    'label' => $displayLabel,
                ];
            } catch (\Throwable $exception) {
                report($exception);
                $failed[] = [
                    'user_id' => (int) $recipient->id,
                    'name' => $recipient->full_name,
                    'error' => $exception->getMessage() ?: __('gift_accounts.create_failed'),
                ];
            }
        }

        return compact('created', 'skipped', 'failed');
    }

    public function existingGiftForRecipient(User $recipient): ?Account
    {
        $accountMorph = (new Account)->getMorphClass();

        $accountId = ActivityLog::query()
            ->where('action', 'account.gift_created')
            ->where('entity_type', $accountMorph)
            ->where('payload->recipient_id', $recipient->id)
            ->orderByDesc('id')
            ->value('entity_id');

        if ($accountId === null) {
            $accountId = ActivityLog::query()
                ->where('action', 'account.gift_created')
                ->where('entity_type', $accountMorph)
                ->whereIn('entity_id', Account::withTrashed()
                    ->where('owner_seller_id', $recipient->id)
                    ->select('id'))
                ->orderByDesc('id')
                ->value('entity_id');
        }

        if ($accountId === null) {
            return null;
        }

        return Account::withTrashed()->find($accountId);
    }

    /**
     * @param  list<int>  $agentIds
     */
    public function sellersForAgents(array $agentIds): Collection
    {
        $agentIds = collect($agentIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();

        if ($agentIds === []) {
            return collect();
        }

        return User::query()
            ->where('role', \App\Enums\UserRole::Seller)
            ->whereIn('parent_id', $agentIds)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'username', 'parent_id']);
    }

    protected function resolveServer(Package $package, ?int $serverId): Server
    {
        if ($serverId !== null) {
            $server = Server::query()->findOrFail($serverId);
            $this->packageService->assertServerAllowed($package, $server);

            return $server;
        }

        return $this->serverSelection->pickLeastBusyForPackage($package);
    }
}
