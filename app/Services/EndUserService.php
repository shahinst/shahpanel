<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EndUserService
{
    public const PORTAL_PASSWORD_REGEX = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/';

    public function __construct(
        protected WalletService $walletService,
    ) {}

    /**
     * @return list<string|object>
     */
    public static function portalPasswordValidationRules(bool $required = false): array
    {
        $rules = ['string', 'min:8', 'max:255', 'regex:'.self::PORTAL_PASSWORD_REGEX];

        if ($required) {
            array_unshift($rules, 'required');
        }

        return $rules;
    }

    public function generatePortalPassword(int $length = 12): string
    {
        $lower = 'abcdefghjkmnpqrstuvwxyz';
        $upper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        $digits = '23456789';
        $all = $lower.$upper.$digits;

        $chars = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];

        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($chars);

        return implode('', $chars);
    }

    /**
     * @return Collection<int, User>
     */
    public function clientsForOwner(User $owner): Collection
    {
        $this->assertCanOwnClients($owner);

        return User::query()
            ->where('parent_id', $owner->id)
            ->where('role', UserRole::Client)
            ->orderBy('full_name')
            ->orderBy('username')
            ->get();
    }

    public function clientsQueryForViewer(User $viewer): Builder
    {
        $query = User::query()->where('role', UserRole::Client);

        if ($viewer->role === UserRole::Admin) {
            return $query->orderBy('full_name')->orderBy('username');
        }

        if ($viewer->role === UserRole::Seller) {
            return $query
                ->where('parent_id', $viewer->id)
                ->orderBy('full_name')
                ->orderBy('username');
        }

        if ($viewer->role === UserRole::Agent) {
            $ownerIds = User::query()
                ->whereIn('id', User::subtreeUserIds($viewer))
                ->whereIn('role', [UserRole::Agent, UserRole::Seller])
                ->pluck('id');

            return $query
                ->whereIn('parent_id', $ownerIds)
                ->orderBy('full_name')
                ->orderBy('username');
        }

        throw new InvalidArgumentException(__('auth.unauthorized'));
    }

    public function canViewerManageClient(User $viewer, User $client): bool
    {
        if ($client->role !== UserRole::Client) {
            return false;
        }

        if ($viewer->role === UserRole::Admin) {
            return true;
        }

        if ($client->parent_id === null) {
            return false;
        }

        if ((int) $viewer->id === (int) $client->parent_id) {
            return true;
        }

        $parent = User::query()->find($client->parent_id);

        if ($parent === null) {
            return false;
        }

        if ($viewer->role === UserRole::Seller) {
            return false;
        }

        if ($viewer->role === UserRole::Agent) {
            return in_array($parent->id, User::subtreeUserIds($viewer), true);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function resolveForAccount(User $owner, array $data): User
    {
        $mode = (string) ($data['client_mode'] ?? 'new');

        if ($mode === 'existing') {
            return $this->attachExistingClient(
                $owner,
                (int) $data['client_user_id'],
                filled($data['client_password'] ?? null) ? (string) $data['client_password'] : null
            );
        }

        return $this->findOrCreateForAccount(
            $owner,
            (string) $data['client_username'],
            (string) $data['client_password'],
            $data['client_full_name'] ?? null
        );
    }

    public function findOrCreateForAccount(User $owner, string $username, string $password, ?string $fullName = null): User
    {
        $this->assertCanOwnClients($owner);

        $username = trim($username);
        $existing = User::query()
            ->where('username', $username)
            ->first();

        if ($existing !== null && $existing->role !== UserRole::Client) {
            throw new InvalidArgumentException(__('clients.username_taken_by_staff'));
        }

        if ($existing !== null && (int) $existing->parent_id !== (int) $owner->id) {
            throw new InvalidArgumentException(__('clients.username_owned_by_other'));
        }

        if ($existing === null) {
            $client = User::query()->create([
                'role' => UserRole::Client,
                'parent_id' => $owner->id,
                'username' => $username,
                'email' => $this->generateClientEmail($username, $owner),
                'password' => Hash::make($password),
                'full_name' => $fullName ?: $username,
                'status' => UserStatus::Active,
            ]);

            $this->walletService->getOrCreateWallet($client);

            return $client;
        }

        $existing->update([
            'password' => Hash::make($password),
            'full_name' => $fullName ?: $existing->full_name,
            'status' => UserStatus::Active,
        ]);

        $this->walletService->getOrCreateWallet($existing);

        return $existing->fresh();
    }

    public function attachExistingClient(User $owner, int $clientUserId, ?string $newPassword = null): User
    {
        $this->assertCanOwnClients($owner);

        $client = User::query()->find($clientUserId);

        if ($client === null || $client->role !== UserRole::Client) {
            throw new InvalidArgumentException(__('clients.invalid_client'));
        }

        if ((int) $client->parent_id !== (int) $owner->id) {
            throw new InvalidArgumentException(__('clients.client_not_owned'));
        }

        if ($newPassword !== null) {
            $client->update(['password' => Hash::make($newPassword)]);
        }

        $client->update(['status' => UserStatus::Active]);
        $this->walletService->getOrCreateWallet($client);

        return $client->fresh();
    }

    /**
     * @return Collection<int, User>
     */
    public function clientOwnersForActor(User $actor): Collection
    {
        if ($actor->role === UserRole::Seller) {
            return collect([$actor]);
        }

        if ($actor->role === UserRole::Agent) {
            $sellerIds = User::query()
                ->ownedByHierarchy($actor, 'id')
                ->where('role', UserRole::Seller)
                ->pluck('id');

            return User::query()
                ->whereIn('id', $sellerIds->push($actor->id)->unique()->values())
                ->orderBy('full_name')
                ->orderBy('username')
                ->get();
        }

        if ($actor->role === UserRole::Admin) {
            return User::query()
                ->where(function ($query) use ($actor): void {
                    $query->where('id', $actor->id)
                        ->orWhereIn('role', [UserRole::Agent, UserRole::Seller]);
                })
                ->orderBy('full_name')
                ->orderBy('username')
                ->get();
        }

        throw new InvalidArgumentException(__('auth.unauthorized'));
    }

    public function resolveClientOwnerForActor(User $actor, ?int $ownerId = null): User
    {
        if ($actor->role === UserRole::Seller) {
            return $actor;
        }

        if ($ownerId === null) {
            throw new InvalidArgumentException(__('clients.invalid_owner'));
        }

        $owner = User::query()->find($ownerId);

        if ($owner === null) {
            throw new InvalidArgumentException(__('clients.invalid_owner'));
        }

        $allowedOwnerIds = $this->clientOwnersForActor($actor)->pluck('id')->all();

        if (! in_array((int) $owner->id, array_map('intval', $allowedOwnerIds), true)) {
            throw new InvalidArgumentException(__('clients.invalid_owner'));
        }

        return $owner;
    }

    public function createStandaloneClient(User $owner, string $username, string $password, ?string $fullName = null): User
    {
        $this->assertCanOwnClients($owner);

        $username = trim($username);
        $existing = User::query()
            ->where('username', $username)
            ->first();

        if ($existing !== null) {
            if ($existing->role !== UserRole::Client) {
                throw new InvalidArgumentException(__('clients.username_taken_by_staff'));
            }

            if ((int) $existing->parent_id !== (int) $owner->id) {
                throw new InvalidArgumentException(__('clients.username_owned_by_other'));
            }

            throw new InvalidArgumentException(__('clients.username_already_exists'));
        }

        $client = User::query()->create([
            'role' => UserRole::Client,
            'parent_id' => $owner->id,
            'username' => $username,
            'email' => $this->generateClientEmail($username, $owner),
            'password' => Hash::make($password),
            'full_name' => $fullName ?: $username,
            'status' => UserStatus::Active,
        ]);

        $this->walletService->getOrCreateWallet($client);

        return $client;
    }

    /**
     * @return array{user: User, password: string}
     */
    public function createAutoClientForAccount(User $owner, ?string $displayName = null): array
    {
        $this->assertCanOwnClients($owner);

        $password = $this->generatePortalPassword();
        $username = null;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = 'c'.Str::lower(Str::random(8));

            if (! User::query()->where('username', $candidate)->exists()) {
                $username = $candidate;
                break;
            }
        }

        if ($username === null) {
            throw new InvalidArgumentException(__('accounts.auto_client_username_failed'));
        }

        $client = User::query()->create([
            'role' => UserRole::Client,
            'parent_id' => $owner->id,
            'username' => $username,
            'email' => $this->generateClientEmail($username, $owner),
            'password' => Hash::make($password),
            'full_name' => filled($displayName) ? trim((string) $displayName) : $username,
            'status' => UserStatus::Active,
        ]);

        $this->walletService->getOrCreateWallet($client);

        return [
            'user' => $client,
            'password' => $password,
        ];
    }

    public function resolvePortalOwner(User $client): User
    {
        if ($client->role !== UserRole::Client) {
            throw new InvalidArgumentException(__('clients.invalid_client'));
        }

        $client->loadMissing('parent');
        $parent = $client->parent;

        if ($parent === null) {
            throw new InvalidArgumentException(__('clients.missing_parent'));
        }

        return $parent;
    }

    /**
     * @return array{owner: User, owner_role_label: string}
     */
    public function portalOwnerContext(User $client): array
    {
        $owner = $this->resolvePortalOwner($client);
        $cardService = app(PaymentCardService::class);
        $visibleCards = $cardService->visiblePaymentCardsForClient($client);

        return [
            'owner' => $owner,
            'owner_role_label' => $owner->role->label(),
            'payment_cards' => $visibleCards,
        ];
    }

    protected function assertCanOwnClients(User $owner): void
    {
        if (! in_array($owner->role, [UserRole::Admin, UserRole::Agent, UserRole::Seller], true)) {
            throw new InvalidArgumentException(__('clients.invalid_owner'));
        }
    }

    protected function generateClientEmail(string $username, User $owner): string
    {
        $base = preg_replace('/[^a-z0-9._-]+/i', '', $username) ?: 'client';

        return strtolower($base).'.'.$owner->id.'@client.shahpanel.local';
    }
}
