<?php

namespace App\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

trait RetriesApiCalls
{
    protected function retryAttempts(): int
    {
        return max(1, (int) config('vpnpanel.sync_api_retry_attempts', 3));
    }

    protected function apiTimeoutSeconds(): int
    {
        return max(1, (int) config('vpnpanel.sync_api_timeout_seconds', 15));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  (callable(Throwable): void)|null  $onException  invoked on each failure (e.g. to evict a pooled client)
     * @return T
     */
    protected function withRetry(callable $callback, string $operation, array $context = [], ?callable $onException = null)
    {
        $attempts = $this->retryAttempts();
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $callback();
            } catch (Throwable $exception) {
                $lastException = $exception;

                if ($onException !== null) {
                    $onException($exception);
                }

                Log::warning('API call failed, retrying', array_merge($context, [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                    'error' => $exception->getMessage(),
                ]));

                if ($attempt < $attempts) {
                    usleep((int) (100_000 * (2 ** ($attempt - 1))));
                }
            }
        }

        Log::error('API call exhausted retries', array_merge($context, [
            'operation' => $operation,
            'attempts' => $attempts,
            'error' => $lastException?->getMessage(),
        ]));

        throw $lastException;
    }
}
