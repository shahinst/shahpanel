<?php

namespace App\Jobs\Tunneling\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Per-router mutual exclusion: jobs touching the same MikroTik must never run
 * concurrently (RouterOS API sessions are cheap but config writes race).
 * Lock misses release the job back to the queue with a delay instead of
 * failing it.
 */
trait LocksServer
{
    protected function withServerLock(int $serverId, callable $callback): void
    {
        $seconds = (int) config('tunneling.queue.server_lock_seconds', 240);
        $lock = Cache::lock("tunneling:server-lock:{$serverId}", $seconds);

        if (! $lock->get()) {
            // Router busy — retry shortly without burning an attempt.
            $this->release(15);

            return;
        }

        try {
            $callback();
        } finally {
            $lock->release();
        }
    }
}
