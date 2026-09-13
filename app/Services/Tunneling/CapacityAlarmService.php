<?php

namespace App\Services\Tunneling;

use App\Enums\ServerType;
use App\Models\Server;

/**
 * Capacity guard: alarms (Telegram, deduplicated) when a router runs hot —
 * sustained CPU, conntrack near table limit, or accounts near the per-role cap.
 */
class CapacityAlarmService
{
    public function __construct(protected TelegramAlertService $telegram)
    {
    }

    public function evaluate(): void
    {
        $cpuLimit = (int) config('tunneling.capacity.cpu_alarm_pct', 70);
        $ctRatio = (float) config('tunneling.capacity.conntrack_alarm_ratio', 0.8);

        $servers = Server::query()
            ->active()
            ->where('type', ServerType::Mikrotik)
            ->whereNotNull('metrics_sampled_at')
            ->where('metrics_sampled_at', '>=', now()->subMinutes(15))
            ->get();

        foreach ($servers as $server) {
            if ($server->last_cpu_pct !== null && (float) $server->last_cpu_pct >= $cpuLimit) {
                $this->telegram->send(
                    "🔥 <b>ظرفیت</b>\nCPU روتر «{$server->name}» روی {$server->last_cpu_pct}% است (آستانه {$cpuLimit}%).",
                    "capacity:cpu:{$server->id}",
                );
            }

            if ($server->last_conntrack !== null
                && $server->last_conntrack_max !== null
                && $server->last_conntrack_max > 0
                && $server->last_conntrack / $server->last_conntrack_max >= $ctRatio) {
                $this->telegram->send(
                    "🔥 <b>ظرفیت</b>\nconntrack روتر «{$server->name}»: {$server->last_conntrack} از {$server->last_conntrack_max}.",
                    "capacity:conntrack:{$server->id}",
                );
            }

            $cap = $server->account_cap ?? $this->defaultCapForRole((string) $server->role);

            if ($cap !== null && $cap > 0) {
                $accounts = $server->accounts()->count();

                if ($accounts >= $cap) {
                    $this->telegram->send(
                        "🔥 <b>ظرفیت</b>\nسرور «{$server->name}» به سقف اکانت رسید: {$accounts}/{$cap}.",
                        "capacity:accounts:{$server->id}",
                    );
                }
            }
        }
    }

    protected function defaultCapForRole(string $role): ?int
    {
        $caps = (array) config('tunneling.capacity.default_account_cap', []);

        return isset($caps[$role]) ? (int) $caps[$role] : null;
    }
}
