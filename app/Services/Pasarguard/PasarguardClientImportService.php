<?php

namespace App\Services\Pasarguard;

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\PasarguardService;
use App\Services\UserHierarchyService;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PasarguardClientImportService
{
    public function __construct(
        protected PasarguardService $pasarguardService,
        protected UserHierarchyService $hierarchyService,
    ) {}

    /**
     * @return array{
     *     clients: list<array<string, mixed>>,
     *     packages: list<array<string, mixed>>,
     *     total: int
     * }
     */
    public function fetchForAssignment(Server $server): array
    {
        if (! $server->isPasarguard()) {
            throw new InvalidArgumentException(__('servers.pasarguard_import_only'));
        }

        set_time_limit(300);

        $remoteUsers = $this->pasarguardService->listAllUsers($server);
        $packages = Package::query()
            ->active()
            ->where('service_type', ServiceType::Pasarguard)
            ->whereHas('servers', fn ($q) => $q->where('servers.id', $server->id))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $existing = Account::query()
            ->withTrashed()
            ->where('server_id', $server->id)
            ->whereNotNull('pasarguard_user_id')
            ->with(['ownerSeller', 'ownerAgent', 'package'])
            ->get()
            ->keyBy('pasarguard_user_id');

        $byUsername = Account::query()
            ->withTrashed()
            ->where('server_id', $server->id)
            ->get()
            ->keyBy(fn (Account $a) => strtolower((string) $a->remote_username));

        $rows = [];

        foreach ($remoteUsers as $remote) {
            $panelId = (int) ($remote['id'] ?? 0);
            $username = (string) ($remote['username'] ?? '');
            if ($username === '') {
                continue;
            }

            $existingAccount = $existing->get($panelId)
                ?? $byUsername->get(strtolower($username));
            $owner = $existingAccount?->ownerSeller ?? $existingAccount?->ownerAgent;
            $dataLimitBytes = isset($remote['data_limit']) ? (int) $remote['data_limit'] : null;
            $matchedPackage = $this->matchPackageByTraffic($packages, $dataLimitBytes);

            $rows[] = [
                'uuid' => (string) $panelId,
                'email' => $username,
                'username' => $username,
                'enable' => ($remote['status'] ?? '') !== 'disabled',
                'status' => (string) ($remote['status'] ?? ''),
                'data_limit_bytes' => $dataLimitBytes,
                'used_bytes' => (int) ($remote['used_traffic'] ?? 0),
                'expiry_time' => $this->parseExpiryMs($remote),
                'subscription_url' => (string) ($remote['subscription_url'] ?? ''),
                'existing_account_id' => $existingAccount?->id,
                'existing_owner_id' => $existingAccount?->owner_seller_id ?? $existingAccount?->owner_agent_id,
                'existing_owner_name' => $owner?->full_name,
                'existing_package_id' => $existingAccount?->package_id ?? $matchedPackage?->id,
                'existing_package_name' => $existingAccount?->package?->name ?? $matchedPackage?->name,
                'suggested_package_id' => $existingAccount?->package_id ?? $matchedPackage?->id,
                'is_already_imported' => $existingAccount !== null,
            ];
        }

        return [
            'clients' => $rows,
            'total' => count($rows),
            'packages' => $packages->map(fn (Package $package): array => [
                'id' => $package->id,
                'name' => $package->name,
                'data_limit_label' => $package->isUnlimited()
                    ? __('servers.unlimited')
                    : persian_digits(rtrim(rtrim(number_format((float) $package->data_limit_gb, 2, '.', ''), '0'), '.')).' GB',
            ])->values()->all(),
        ];
    }

    /**
     * @param  list<array{uuid: string, owner_id: int, package_id?: int|null, update_existing?: bool}>  $assignments
     * @return array{created: int, updated: int, skipped: int, errors: list<string>, lines: list<string>}
     */
    public function import(Server $server, array $assignments): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $lines = [];

        $remoteById = collect($this->pasarguardService->listAllUsers($server))
            ->keyBy(fn (array $row): int => (int) ($row['id'] ?? 0));

        foreach ($assignments as $assignment) {
            $panelUserId = (int) ($assignment['uuid'] ?? 0);
            $ownerId = (int) ($assignment['owner_id'] ?? 0);
            $packageId = isset($assignment['package_id']) && $assignment['package_id'] !== ''
                ? (int) $assignment['package_id']
                : null;
            $updateExisting = (bool) ($assignment['update_existing'] ?? false);

            if ($panelUserId <= 0 || $ownerId <= 0) {
                continue;
            }

            $owner = User::query()->find($ownerId);
            if ($owner === null || ! in_array($owner->role, [UserRole::Agent, UserRole::Seller], true)) {
                $errors[] = "مالک نامعتبر برای شناسه {$panelUserId}.";

                continue;
            }

            try {
                [$sellerId, $agentId] = $this->resolveOwnership($owner);
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();

                continue;
            }

            $package = $packageId ? Package::query()->find($packageId) : null;

            $remote = $remoteById->get($panelUserId);
            if (! is_array($remote)) {
                try {
                    $remote = $this->pasarguardService->getUser($server, (string) ($assignment['username'] ?? $panelUserId));
                } catch (\Throwable $e) {
                    $errors[] = "شناسه {$panelUserId}: ".$e->getMessage();

                    continue;
                }
            }

            $username = (string) ($remote['username'] ?? '');
            if ($username === '') {
                $errors[] = "شناسه {$panelUserId}: نام کاربری خالی است.";

                continue;
            }

            $panelUserId = (int) ($remote['id'] ?? $panelUserId);
            $dataLimitBytes = isset($remote['data_limit']) ? (int) $remote['data_limit'] : null;
            $expiryAt = $this->expiryFromRemote($remote);
            $status = $this->mapRemoteStatus($remote);

            $existing = Account::query()
                ->withTrashed()
                ->where('server_id', $server->id)
                ->where(function ($q) use ($panelUserId, $username) {
                    $q->where('pasarguard_user_id', $panelUserId)
                        ->orWhere('remote_username', $username);
                })
                ->first();

            if ($existing !== null) {
                if ($updateExisting) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }

                    $existing->update([
                        'owner_seller_id' => $sellerId,
                        'owner_agent_id' => $agentId,
                        'package_id' => $package?->id ?? $existing->package_id,
                        'pasarguard_user_id' => $panelUserId,
                        'pasarguard_subscription_url' => (string) ($remote['subscription_url'] ?? $existing->pasarguard_subscription_url),
                        'data_limit_bytes' => $dataLimitBytes,
                        'data_used_bytes' => (int) ($remote['used_traffic'] ?? $existing->data_used_bytes),
                        'expiry_at' => $expiryAt,
                        'status' => $status,
                        'last_sync_at' => now(),
                    ]);
                    $updated++;
                    $lines[] = "«{$username}» بروزرسانی شد.";
                } else {
                    $skipped++;
                    $lines[] = "«{$username}» قبلاً وارد شده — رد شد.";
                }

                continue;
            }

            Account::query()->create([
                'owner_seller_id' => $sellerId,
                'owner_agent_id' => $agentId,
                'package_id' => $package?->id,
                'server_id' => $server->id,
                    'service_type' => $package?->service_type ?? ServiceType::Pasarguard,
                'remote_username' => $username,
                'remote_password_enc' => Str::password(12),
                'client_email' => $username,
                'pasarguard_user_id' => $panelUserId,
                'pasarguard_subscription_url' => (string) ($remote['subscription_url'] ?? ''),
                'portal_token' => Str::random((int) config('vpnpanel.portal_token_length', 32)),
                'data_limit_bytes' => $dataLimitBytes,
                'data_used_bytes' => (int) ($remote['used_traffic'] ?? 0),
                'expiry_at' => $expiryAt,
                'status' => $status,
                'last_sync_at' => now(),
            ]);
            $created++;
            $lines[] = "«{$username}» وارد شد.";
        }

        return compact('created', 'updated', 'skipped', 'errors', 'lines');
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function resolveOwnership(User $owner): array
    {
        if ($owner->role === UserRole::Agent) {
            return [$owner->id, $owner->id];
        }

        $chain = $this->hierarchyService->resolveCommissionChain($owner);

        return [$owner->id, $chain['owner_agent_id']];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Package>  $packages
     */
    protected function matchPackageByTraffic($packages, ?int $bytes): ?Package
    {
        if ($bytes === null || $bytes <= 0) {
            return $packages->first();
        }

        $gb = $bytes / (1024 ** 3);

        return $packages->sortBy(fn (Package $p) => abs((float) ($p->data_limit_gb ?? 0) - $gb))->first();
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    protected function parseExpiryMs(array $remote): int
    {
        $expiry = $remote['expire'] ?? null;
        if ($expiry === null || $expiry === '') {
            return 0;
        }

        if (is_numeric($expiry)) {
            $ts = (int) $expiry;

            return $ts > 9999999999 ? $ts : $ts * 1000;
        }

        $parsed = strtotime((string) $expiry);

        return $parsed ? $parsed * 1000 : 0;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    protected function expiryFromRemote(array $remote): ?\Illuminate\Support\Carbon
    {
        $expiry = $remote['expire'] ?? null;
        if ($expiry === null || $expiry === '') {
            return null;
        }

        if (is_numeric($expiry)) {
            $ts = (int) $expiry;

            return \Illuminate\Support\Carbon::createFromTimestamp($ts > 9999999999 ? (int) ($ts / 1000) : $ts);
        }

        return \Illuminate\Support\Carbon::parse((string) $expiry);
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    protected function mapRemoteStatus(array $remote): AccountStatus
    {
        $status = (string) ($remote['status'] ?? 'active');

        return match ($status) {
            'disabled', 'expired' => AccountStatus::Disabled,
            default => AccountStatus::Active,
        };
    }
}
