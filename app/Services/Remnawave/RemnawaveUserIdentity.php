<?php

namespace App\Services\Remnawave;

/**
 * Dual-compat helpers for Remnawave user identity across API v2.x and v3.x.
 *
 * v2 identifies users by string `uuid` (path + PATCH body).
 * v3 identifies users by numeric `id` (path + PATCH body); `uuid` is gone.
 * PATCH with `username` works on both lines.
 */
final class RemnawaveUserIdentity
{
    public static function isNumericId(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && ctype_digit($value);
    }

    public static function isUuid(string $value): bool
    {
        $value = trim($value);

        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        );
    }

    /**
     * Value to persist in accounts.remnawave_uuid (numeric id on v3, uuid on v2).
     */
    public static function fromRemoteUser(array $remote): ?string
    {
        if (array_key_exists('id', $remote) && $remote['id'] !== null && $remote['id'] !== '') {
            $id = trim((string) $remote['id']);
            if ($id !== '' && ctype_digit($id)) {
                return $id;
            }
        }

        $uuid = trim((string) ($remote['uuid'] ?? ''));

        return $uuid !== '' ? $uuid : null;
    }

    /**
     * Identity fields for PATCH /api/users.
     *
     * Prefer username (works on v2 and v3). Otherwise send id (v3) or uuid (v2).
     *
     * @return array{username?: string, id?: int, uuid?: string}
     */
    public static function patchIdentity(string $storedIdentifier, ?string $username = null): array
    {
        $username = trim((string) $username);
        if ($username !== '') {
            return ['username' => $username];
        }

        $stored = trim($storedIdentifier);
        if (self::isNumericId($stored)) {
            return ['id' => (int) $stored];
        }

        if ($stored !== '') {
            return ['uuid' => $stored];
        }

        return [];
    }

    /**
     * Flatten v3 nested traffic / keep v2 top-level fields for callers.
     *
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    public static function normalizeUserShape(array $remote): array
    {
        $traffic = $remote['userTraffic'] ?? null;
        if (is_array($traffic)) {
            if (! array_key_exists('usedTrafficBytes', $remote) && array_key_exists('usedTrafficBytes', $traffic)) {
                $remote['usedTrafficBytes'] = $traffic['usedTrafficBytes'];
            }
            if (! array_key_exists('lifetimeUsedTrafficBytes', $remote) && array_key_exists('lifetimeUsedTrafficBytes', $traffic)) {
                $remote['lifetimeUsedTrafficBytes'] = $traffic['lifetimeUsedTrafficBytes'];
            }
            if (! array_key_exists('onlineAt', $remote) && array_key_exists('onlineAt', $traffic)) {
                $remote['onlineAt'] = $traffic['onlineAt'];
            }
        }

        // Expose a stable uuid key for legacy callers (v3 uses numeric id).
        if (! isset($remote['uuid']) || $remote['uuid'] === '' || $remote['uuid'] === null) {
            $identity = self::fromRemoteUser($remote);
            if ($identity !== null) {
                $remote['uuid'] = $identity;
            }
        }

        return $remote;
    }
}
