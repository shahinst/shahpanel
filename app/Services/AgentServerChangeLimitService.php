<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AccountServerChange;
use App\Models\Setting;
use App\Models\User;
use InvalidArgumentException;

class AgentServerChangeLimitService
{
    public function dailyLimitForAgent(User $agent): int
    {
        if ($agent->daily_server_change_limit !== null) {
            return max(0, (int) $agent->daily_server_change_limit);
        }

        return max(0, (int) Setting::getValue('default_agent_daily_server_changes', 5));
    }

    public function changesToday(User $agent): int
    {
        return AccountServerChange::query()
            ->where('changed_by_user_id', $agent->id)
            ->whereDate('created_at', today())
            ->count();
    }

    public function remainingToday(User $agent): int
    {
        return max(0, $this->dailyLimitForAgent($agent) - $this->changesToday($agent));
    }

    public function assertCanChange(User $actor): void
    {
        if ($actor->role !== UserRole::Agent) {
            return;
        }

        $limit = $this->dailyLimitForAgent($actor);

        if ($limit <= 0) {
            throw new InvalidArgumentException('تغییر سرور اکانت برای این نماینده غیرفعال است.');
        }

        if ($this->changesToday($actor) >= $limit) {
            throw new InvalidArgumentException(
                "سقف تغییر سرور روزانه ({$limit} بار) برای امروز تکمیل شده است."
            );
        }
    }

    public function record(User $actor, int $accountId, int $oldServerId, int $newServerId): void
    {
        AccountServerChange::query()->create([
            'account_id' => $accountId,
            'changed_by_user_id' => $actor->id,
            'old_server_id' => $oldServerId,
            'new_server_id' => $newServerId,
        ]);
    }
}
