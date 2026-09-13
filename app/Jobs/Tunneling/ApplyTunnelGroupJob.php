<?php

namespace App\Jobs\Tunneling;

use App\Enums\DesiredObjectStatus;
use App\Enums\TunnelGroupStatus;
use App\Models\DesiredNetworkObject;
use App\Models\TunnelGroup;
use App\Models\TunnelGroupEvent;
use App\Services\RouterOs\DesiredStateApplier;
use App\Services\Tunneling\ManagedInterfaceService;

/**
 * Converges every involved router (Iran + all exits) to desired state.
 * Applies ALL pending objects on each server (tunnel group + managed WG/PPP
 * interfaces) so «کانفیگ و تست» delivers a complete stack in one pass.
 */
class ApplyTunnelGroupJob extends BaseTunnelingJob
{
    public function __construct(public int $groupId)
    {
        parent::__construct();
    }

    public function handle(DesiredStateApplier $applier, ManagedInterfaceService $interfaces): void
    {
        $group = TunnelGroup::query()->with('iranServer', 'exits.server')->find($this->groupId);

        if ($group === null) {
            return;
        }

        $servers = collect([$group->iranServer])
            ->merge($group->exits->map->server)
            ->filter()
            ->unique('id');

        $totals = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];
        $errors = [];
        $failureDetails = [];

        foreach ($servers as $server) {
            $this->withServerLock($server->id, function () use ($applier, $interfaces, $server, $group, &$totals, &$errors, &$failureDetails): void {
                $stats = $applier->applyForServerBatched($server);

                foreach ($stats as $key => $value) {
                    $totals[$key] += $value;
                }

                $interfaces->finalizeForServer($server->id);

                if ($stats['failed'] > 0) {
                    $failedObjects = DesiredNetworkObject::query()
                        ->where('server_id', $server->id)
                        ->where(function ($query) use ($group): void {
                            $query->where('tunnel_group_id', $group->id)
                                ->orWhereNull('tunnel_group_id');
                        })
                        ->where('status', DesiredObjectStatus::Error)
                        ->orderBy('id')
                        ->limit(20)
                        ->get(['menu', 'marker', 'last_error']);

                    foreach ($failedObjects as $object) {
                        $failureDetails[] = [
                            'server' => $server->name,
                            'menu' => $object->menu,
                            'marker' => $object->marker,
                            'error' => $object->last_error,
                        ];
                    }

                    $errors[] = $server->name.' ('.$stats['failed'].' خطا)';
                }
            });
        }

        $statusMessage = null;

        if ($errors !== []) {
            $statusMessage = 'خطا روی: '.implode('، ', $errors);

            if ($failureDetails !== []) {
                $first = $failureDetails[0];
                $statusMessage .= ' — '.$first['menu'].': '.mb_substr((string) $first['error'], 0, 180);
            }
        }

        $group->forceFill([
            'status' => $totals['failed'] > 0 ? TunnelGroupStatus::Error : TunnelGroupStatus::Active,
            'status_message' => $statusMessage,
            'last_applied_at' => now(),
            'meta' => array_merge($group->meta ?? [], [
                'last_apply' => [
                    'applied_at' => now()->toIso8601String(),
                    ...$totals,
                    'failures' => $failureDetails,
                ],
            ]),
        ])->save();

        TunnelGroupEvent::record(
            'apply',
            "اعمال گروه «{$group->name}»: {$totals['created']} ساخت، {$totals['updated']} اصلاح، {$totals['unchanged']} بدون تغییر، {$totals['removed']} حذف، {$totals['failed']} خطا.",
            [
                'tunnel_group_id' => $group->id,
                'detail' => $failureDetails === [] ? null : ['failures' => $failureDetails],
            ],
            $totals['failed'] > 0 ? 'error' : 'ok',
        );
    }
}
