<?php

namespace Modules\ShahBot\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may run their own sales bot.
 *
 * The admin grants agents (and sellers directly under the admin); an agent
 * grants their own sellers. A seller's access also needs the agent above them
 * to still have it: taking an agent's bot away takes their sellers' with it,
 * without anyone having to remember to untick each one.
 */
class BotAccess
{
    public function allows(?User $user): bool
    {
        if ($user === null || ! in_array($user->role, [UserRole::Agent, UserRole::Seller], true)) {
            return false;
        }

        if (! $this->granted($user->id)) {
            return false;
        }

        if ($user->role === UserRole::Seller) {
            $parent = $user->parent;

            if ($parent !== null && $parent->role === UserRole::Agent) {
                return $this->granted($parent->id);
            }
        }

        return true;
    }

    /**
     * Whether $actor may switch $target's access on or off.
     */
    public function canManage(User $actor, User $target): bool
    {
        if ($actor->role === UserRole::Admin) {
            return in_array($target->role, [UserRole::Agent, UserRole::Seller], true);
        }

        // An agent hands out only what they hold themselves, and only to
        // sellers directly under them.
        return $actor->role === UserRole::Agent
            && $target->role === UserRole::Seller
            && (int) $target->parent_id === (int) $actor->id
            && $this->allows($actor);
    }

    public function set(User $actor, User $target, bool $on): void
    {
        if (! $on) {
            DB::table('shahbot_bot_access')->where('user_id', $target->id)->delete();

            return;
        }

        DB::table('shahbot_bot_access')->updateOrInsert(
            ['user_id' => $target->id],
            ['granted_by_user_id' => $actor->id, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * @return Collection<int, int> ids of users holding a grant
     */
    public function grantedIds(): Collection
    {
        return DB::table('shahbot_bot_access')->pluck('user_id')->map(fn ($id) => (int) $id);
    }

    protected function granted(int $userId): bool
    {
        return DB::table('shahbot_bot_access')->where('user_id', $userId)->exists();
    }
}
