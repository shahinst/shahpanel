<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Live progress of a long server operation (push every account, sync every
 * account's usage), written to the cache so the server page can poll it and
 * draw a console and a progress bar while the work runs after the response.
 *
 * Writes are throttled: the state is saved at most every FLUSH_INTERVAL
 * seconds, and always on begin/finish, so a 500-account run does not turn
 * into 500 cache writes of a growing log.
 */
class OperationProgress
{
    public const MAX_LINES = 5000;

    protected const FLUSH_INTERVAL = 0.25;

    /** @var array<string, mixed> */
    protected array $state;

    protected float $startedAt;

    protected float $lastFlush = 0.0;

    public function __construct(
        protected string $cacheKey,
        string $operation,
    ) {
        $this->startedAt = microtime(true);
        $this->state = [
            'operation' => $operation,
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'total' => 0,
            'done' => 0,
            'ok' => 0,
            'failed' => 0,
            'skipped' => 0,
            'elapsed' => 0.0,
            'seq' => 0,
            'lines' => [],
            'type' => null,
            'message' => null,
        ];
        $this->flush(true);
    }

    public function begin(int $total, ?string $line = null): void
    {
        $this->state['total'] = max(0, $total);

        if ($line !== null) {
            $this->log($line);
        }

        $this->flush(true);
    }

    /**
     * @param  'info'|'ok'|'warn'|'error'|'skip'  $level
     */
    public function log(string $text, string $level = 'info'): void
    {
        $this->state['seq']++;
        $this->state['lines'][] = [
            'seq' => $this->state['seq'],
            't' => round(microtime(true) - $this->startedAt, 2),
            'level' => $level,
            'text' => $text,
        ];

        if (count($this->state['lines']) > self::MAX_LINES) {
            $this->state['lines'] = array_slice($this->state['lines'], -self::MAX_LINES);
        }

        $this->flush();
    }

    /**
     * One account finished.
     *
     * @param  'ok'|'error'|'skip'  $outcome
     */
    public function advance(string $text, string $outcome = 'ok'): void
    {
        $this->state['done']++;

        match ($outcome) {
            'error' => $this->state['failed']++,
            'skip' => $this->state['skipped']++,
            default => $this->state['ok']++,
        };

        $total = max($this->state['total'], $this->state['done']);
        $width = strlen((string) $total);
        $prefix = sprintf('[%'.$width.'d/%d]', $this->state['done'], $total);

        $this->log($prefix.' '.$text, $outcome);
    }

    /**
     * @param  'success'|'warning'|'error'  $type
     */
    public function finish(string $type, string $message): void
    {
        $this->state['status'] = 'finished';
        $this->state['type'] = $type;
        $this->state['message'] = $message;
        $this->state['finished_at'] = now()->toIso8601String();
        $this->log($message, $type === 'success' ? 'ok' : ($type === 'warning' ? 'warn' : 'error'));
        $this->flush(true);
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    protected function flush(bool $force = false): void
    {
        $now = microtime(true);

        if (! $force && ($now - $this->lastFlush) < self::FLUSH_INTERVAL) {
            return;
        }

        $this->lastFlush = $now;
        $this->state['elapsed'] = round($now - $this->startedAt, 2);
        Cache::put($this->cacheKey, $this->state, now()->addDay());
    }

    /**
     * What the page polls: counters, percent, timing and the log lines after
     * the sequence number the page already has.
     *
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>|null
     */
    public static function snapshot(?array $state, int $afterSeq = 0): ?array
    {
        if (! is_array($state) || ! array_key_exists('seq', $state)) {
            return null;
        }

        $total = (int) $state['total'];
        $done = (int) $state['done'];
        $running = ($state['status'] ?? '') === 'running';
        // While running, the clock keeps going between the throttled writes.
        $elapsed = (float) $state['elapsed'];

        if ($running && ! empty($state['started_at'])) {
            $sinceStart = microtime(true) - \Illuminate\Support\Carbon::parse($state['started_at'])->getTimestamp();
            $elapsed = max($elapsed, $sinceStart);
        }
        $percent = $total > 0 ? min(100, $done / $total * 100) : ($running ? 0 : 100);
        $rate = $elapsed > 0 && $done > 0 ? $done / $elapsed : null;
        $eta = $running && $rate !== null && $total > $done ? ($total - $done) / $rate : null;

        $lines = array_values(array_filter(
            (array) $state['lines'],
            static fn (array $line): bool => (int) $line['seq'] > $afterSeq
        ));

        return [
            'operation' => $state['operation'],
            'status' => $state['status'],
            'type' => $state['type'],
            'message' => $state['message'],
            'started_at' => $state['started_at'],
            'finished_at' => $state['finished_at'],
            'total' => $total,
            'done' => $done,
            'ok' => (int) $state['ok'],
            'failed' => (int) $state['failed'],
            'skipped' => (int) $state['skipped'],
            'percent' => round($percent, 2),
            'elapsed' => round($elapsed, 1),
            'rate' => $rate !== null ? round($rate, 2) : null,
            'eta' => $eta !== null ? (int) ceil($eta) : null,
            'seq' => (int) $state['seq'],
            'lines' => $lines,
        ];
    }
}
