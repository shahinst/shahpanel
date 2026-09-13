<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Services\WalletService;
use InvalidArgumentException;

class UserHierarchyService
{
    public function __construct(
        protected WalletService $walletService,
    ) {}

    /**
     * @return array{agent: ?User, admin: User, owner_agent_id: int}
     */
    public function resolveCommissionChain(User $buyer): array
    {
        $admin = $this->resolveAdmin();

        if ($buyer->role === UserRole::Agent) {
            return [
                'agent' => null,
                'admin' => $admin,
                'owner_agent_id' => $buyer->id,
            ];
        }

        if ($buyer->role !== UserRole::Seller) {
            throw new InvalidArgumentException('فقط نماینده یا فروشنده می‌تواند اکانت بخرد.');
        }
        $parent = $buyer->parent;
        $agent = ($parent !== null && $parent->role === UserRole::Agent) ? $parent : null;

        $ownerAgentId = $agent?->id ?? $admin->id;

        return [
            'agent' => $agent,
            'admin' => $admin,
            'owner_agent_id' => $ownerAgentId,
        ];
    }

    public function promoteSellerToAgent(User $seller): User
    {
        if ($seller->role !== UserRole::Seller) {
            throw new InvalidArgumentException('فقط فروشنده قابل ارتقا به نماینده است.');
        }

        $admin = $this->resolveAdmin();
        $defaultLimit = (int) Setting::getValue('default_agent_daily_server_changes', 5);

        $seller->update([
            'role' => UserRole::Agent,
            'parent_id' => $admin->id,
            'daily_server_change_limit' => $seller->daily_server_change_limit ?? $defaultLimit,
        ]);

        $this->walletService->getOrCreateWallet($seller->fresh());

        return $seller->fresh();
    }

    public function validateSellerParent(?int $parentId): User
    {
        if ($parentId === null) {
            throw new InvalidArgumentException('والد فروشنده مشخص نیست.');
        }

        $parent = User::query()->findOrFail($parentId);

        if (! in_array($parent->role, [UserRole::Agent], true)) {
            throw new InvalidArgumentException('فروشنده فقط می‌تواند زیرمجموعه نماینده باشد.');
        }

        return $parent;
    }

    protected function resolveAdmin(): User
    {
        $admin = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();

        if ($admin === null) {
            throw new InvalidArgumentException('کاربر ادمین یافت نشد.');
        }

        return $admin;
    }
}
