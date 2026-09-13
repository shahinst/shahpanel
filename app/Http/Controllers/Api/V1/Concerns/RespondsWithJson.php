<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * One envelope for every endpoint so a bot can branch on `ok` alone.
 */
trait RespondsWithJson
{
    protected function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['ok' => true, 'data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    protected function fail(string $code, string $message, int $status = 400, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['ok' => false, 'error' => $error], $status);
    }

    /**
     * @param  callable(mixed): array<string, mixed>  $transform
     */
    protected function paginated(LengthAwarePaginator $paginator, callable $transform): JsonResponse
    {
        return $this->ok(
            array_map($transform, $paginator->items()),
            [
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ],
        );
    }

    /** Clamp client-supplied page size to something the panel can serve. */
    protected function perPage(mixed $requested, int $default = 25, int $max = 100): int
    {
        $value = is_numeric($requested) ? (int) $requested : $default;

        return max(1, min($max, $value));
    }
}
