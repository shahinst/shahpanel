<?php

namespace App\Console\Commands;

use App\Models\BlockedIp;
use App\Services\FirewallService;
use App\Services\IpGuardService;
use Illuminate\Console\Command;

/**
 * The panel's escape hatch. Everything the admin screens do is reachable here
 * too, so a lockout is always recoverable from a shell.
 */
class FirewallCommand extends Command
{
    protected $signature = 'firewall
        {action : status|init|block|unblock|flush|prune|resync|cn-load|cn-clear}
        {ip? : address for block/unblock}
        {--minutes=60 : block duration, 0 for indefinite}';

    protected $description = 'Inspect and control the panel firewall';

    public function handle(FirewallService $firewall, IpGuardService $guard): int
    {
        $action = (string) $this->argument('action');
        $ip = $this->argument('ip');

        return match ($action) {
            'status' => $this->status($firewall),
            'init' => $this->report($firewall->init(), 'firewall initialised'),
            'block' => $this->block($guard, $ip),
            'unblock' => $this->unblock($guard, $ip),
            'flush' => $this->flush($firewall),
            'prune' => $this->report(true, $guard->pruneExpired().' expired block(s) lifted'),
            'resync' => $this->resync($firewall, $guard),
            'cn-load' => $this->report($firewall->loadCountryBlock(), 'country ranges loaded'),
            'cn-clear' => $this->report($firewall->clearCountryBlock(), 'country block cleared'),
            default => $this->refuse('unknown action: '.$action),
        };
    }

    protected function status(FirewallService $firewall): int
    {
        $status = $firewall->status();

        $this->table(['key', 'value'], [
            ['helper available', $status['available'] ? 'yes' : 'no'],
            ['iptables chain', $status['chain']],
            ['addresses in firewall', $status['blocked']],
            ['country ranges', $status['cn']],
            ['active blocks (db)', BlockedIp::query()->active()->count()],
        ]);

        return self::SUCCESS;
    }

    protected function block(IpGuardService $guard, ?string $ip): int
    {
        if ($ip === null) {
            return $this->refuse('an ip is required');
        }

        $block = $guard->block($ip, reason: 'manual', minutes: (int) $this->option('minutes'));

        if ($block === null) {
            return $this->refuse('refused: not a routable address, or whitelisted');
        }

        $guard->escalateToFirewall($block);

        return $this->report(true, 'blocked '.$ip);
    }

    protected function unblock(IpGuardService $guard, ?string $ip): int
    {
        if ($ip === null) {
            return $this->refuse('an ip is required');
        }

        $block = BlockedIp::query()->where('ip', $ip)->first();

        if ($block === null) {
            return $this->refuse('no record for '.$ip);
        }

        $guard->unblock($block);

        return $this->report(true, 'unblocked '.$ip);
    }

    protected function flush(FirewallService $firewall): int
    {
        $firewall->flush();

        BlockedIp::query()->active()->update([
            'unblocked_at' => now(),
            'in_firewall' => false,
        ]);

        return $this->report(true, 'every block lifted');
    }

    /** Re-apply what the database says, e.g. after a reboot. */
    protected function resync(FirewallService $firewall, IpGuardService $guard): int
    {
        $firewall->init();
        $guard->pruneExpired();

        $count = 0;

        foreach (BlockedIp::query()->active()->where('in_firewall', true)->get() as $block) {
            $seconds = $block->expires_at !== null
                ? max(60, (int) now()->diffInSeconds($block->expires_at, false))
                : 0;

            if ($firewall->block($block->ip, $seconds)) {
                $count++;
            }
        }

        return $this->report(true, "re-applied {$count} block(s)");
    }

    protected function report(bool $ok, string $message): int
    {
        $ok ? $this->info($message) : $this->error('failed: '.$message);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    protected function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
