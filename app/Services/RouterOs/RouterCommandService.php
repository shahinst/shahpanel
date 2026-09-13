<?php

namespace App\Services\RouterOs;

use App\Models\DesiredNetworkObject;
use App\Models\Server;
use App\Services\MikrotikService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Idempotent RouterOS primitives — the ONLY write path of the new tunneling
 * system. Every managed object is identified by a unique marker stored in its
 * comment field (vpnl:<type>:<key>); ensure() converges the actual router row
 * to the desired payload no matter how many times it runs:
 *
 *   - no row with marker      -> add
 *   - row exists, fields diff -> set (only changed attributes)
 *   - row exists, no diff     -> no-op
 *   - duplicate marker rows   -> keep first, remove the rest
 */
class RouterCommandService
{
    public const RESULT_CREATED = 'created';

    public const RESULT_UPDATED = 'updated';

    public const RESULT_UNCHANGED = 'unchanged';

    public const RESULT_REMOVED = 'removed';

    /**
     * Payload keys treated as write-only: applied on create, never diffed
     * (RouterOS hides or rewrites them on print — passwords, private keys).
     */
    private const CREATE_ONLY_KEYS = [
        'password',
        'private-key',
        'secret',
    ];

    /**
     * Tunnel agent interface menus — the same logical name must not exist on
     * two menus (e.g. after GRE → IPIP switch the old GRE row blocks /add).
     *
     * @var list<string>
     */
    private const TUNNEL_INTERFACE_MENUS = [
        '/interface/gre',
        '/interface/gre6',
        '/interface/ipip',
        '/interface/eoip',
        '/interface/vxlan',
        '/interface/l2tp-ether',
        '/interface/l2tp-client',
    ];

    public function __construct(protected MikrotikService $mikrotik)
    {
    }

    /**
     * Converge one desired object on the router. Returns one of the RESULT_*
     * constants describing what actually happened.
     */
    public function ensure(Server $server, DesiredNetworkObject $object): string
    {
        $menu = rtrim($object->menu, '/');
        $payload = $this->normalizePayload($object->payload);

        // Singleton menus (e.g. /interface/l2tp-server/server) have exactly one
        // implicit row: no marker, no add/remove — only set when values differ.
        if ($object->object_type === 'singleton') {
            return $this->ensureSingleton($server, $menu, $payload);
        }

        $rows = $this->findByMarker($server, $menu, $object->marker);

        if ($rows === []) {
            $name = isset($payload['name']) ? (string) $payload['name'] : null;

            if ($name !== '' && $this->isTunnelInterfaceMenu($menu)) {
                $this->removeConflictingTunnelInterface($server, $menu, $name, $object->marker);
            }

            $this->addWithInterfaceRetry($server, $menu, $payload, $object->marker, $name);

            return self::RESULT_CREATED;
        }

        // Idempotency guard: a marker must map to exactly one row.
        $primary = array_shift($rows);
        foreach ($rows as $duplicate) {
            $this->removeRow($server, $menu, $duplicate);
        }

        $diff = $this->diff($primary, $payload);

        if ($diff === []) {
            return self::RESULT_UNCHANGED;
        }

        $this->mikrotik->sendCommand($server, $menu.'/set', $diff + ['.id' => (string) $primary['.id']]);

        return self::RESULT_UPDATED;
    }

    /**
     * Converge a singleton menu (print → diff → set, no marker involved).
     *
     * @param  array<string, string>  $payload
     */
    protected function ensureSingleton(Server $server, string $menu, array $payload): string
    {
        $rows = $this->mikrotik->queryRouter($server, $menu.'/print');
        $current = $rows[0] ?? [];
        $diff = $this->diff($current, $payload);

        if ($diff === []) {
            return self::RESULT_UNCHANGED;
        }

        $this->mikrotik->sendCommand($server, $menu.'/set', $diff);

        return self::RESULT_UPDATED;
    }

    /**
     * Remove every row carrying the marker. Safe to call repeatedly.
     */
    public function ensureRemoved(Server $server, string $menu, string $marker): string
    {
        $menu = rtrim($menu, '/');
        $rows = $this->findByMarker($server, $menu, $marker);

        foreach ($rows as $row) {
            $this->removeRow($server, $menu, $row);
        }

        return $rows === [] ? self::RESULT_UNCHANGED : self::RESULT_REMOVED;
    }

    /**
     * Actual router row for a marker (first match) or null.
     *
     * @return array<string, mixed>|null
     */
    public function readActual(Server $server, string $menu, string $marker): ?array
    {
        $rows = $this->findByMarker($server, rtrim($menu, '/'), $marker);

        return $rows[0] ?? null;
    }

    /**
     * Whether the actual row matches the desired payload (drift check, read-only).
     */
    public function matchesDesired(Server $server, DesiredNetworkObject $object): bool
    {
        $actual = $this->readActual($server, $object->menu, $object->marker);

        if ($actual === null) {
            return false;
        }

        return $this->diff($actual, $this->normalizePayload($object->payload)) === [];
    }

    /**
     * All rows in a menu whose comment starts with the panel marker prefix —
     * used by the reconciler to find orphans (actual objects without desired
     * counterparts).
     *
     * @return list<array<string, mixed>>
     */
    public function listManagedRows(Server $server, string $menu, string $markerPrefix = ''): array
    {
        $prefix = $markerPrefix !== '' ? $markerPrefix : config('tunneling.marker_prefix', 'vpnl').':';

        try {
            $rows = $this->mikrotik->queryRouterRegexFilter(
                $server,
                $menu,
                'comment',
                rtrim($prefix, ':'),
                ['.id', 'comment'],
            );
        } catch (Throwable $e) {
            Log::warning('tunneling: listManagedRows failed', [
                'server_id' => $server->id,
                'menu' => $menu,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => str_starts_with((string) ($row['comment'] ?? ''), $prefix),
        ));
    }

    /**
     * Remove every row in a menu whose comment matches a regex (router-side filter).
     */
    public function removeRowsMatchingComment(Server $server, string $menu, string $pattern): int
    {
        $menu = rtrim($menu, '/');
        $removed = 0;

        try {
            $rows = $this->mikrotik->queryRouterRegexFilter($server, $menu, 'comment', $pattern, ['.id']);
        } catch (Throwable $e) {
            Log::warning('tunneling: regex comment filter failed, falling back to full print', [
                'server_id' => $server->id,
                'menu' => $menu,
                'error' => $e->getMessage(),
            ]);
            $prefix = str_contains($pattern, ':') ? $pattern : $pattern.':';
            $rows = array_map(
                fn (array $row): array => ['.id' => $row['.id'] ?? null],
                $this->listManagedRows($server, $menu, $prefix),
            );
        }

        foreach ($rows as $row) {
            $id = $row['.id'] ?? null;

            if ($id === null) {
                continue;
            }

            try {
                $this->removeRow($server, $menu, $row);
                $removed++;
            } catch (Throwable $e) {
                Log::warning('tunneling: remove row failed', [
                    'server_id' => $server->id,
                    'menu' => $menu,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $removed;
    }

    /**
     * Remove every row in a menu whose name matches a regex.
     */
    public function removeRowsMatchingName(Server $server, string $menu, string $pattern): int
    {
        $menu = rtrim($menu, '/');
        $removed = 0;

        try {
            $rows = $this->mikrotik->queryRouterRegexFilter($server, $menu, 'name', $pattern, ['.id', 'name']);
        } catch (Throwable $e) {
            Log::warning('tunneling: removeRowsMatchingName failed', [
                'server_id' => $server->id,
                'menu' => $menu,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        foreach ($rows as $row) {
            $id = $row['.id'] ?? null;

            if ($id === null) {
                continue;
            }

            try {
                $this->removeRow($server, $menu, $row);
                $removed++;
            } catch (Throwable $e) {
                Log::warning('tunneling: remove row failed', [
                    'server_id' => $server->id,
                    'menu' => $menu,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $removed;
    }

    /**
     * Remove rows found by exact name (fast point lookup).
     */
    public function removeRowsByName(Server $server, string $menu, string $name): int
    {
        $menu = rtrim($menu, '/');
        $removed = 0;

        try {
            $rows = $this->mikrotik->queryRouter($server, $menu.'/print', ['name' => $name]);
        } catch (Throwable $e) {
            return 0;
        }

        foreach ($rows as $row) {
            try {
                $this->removeRow($server, $menu, $row);
                $removed++;
            } catch (Throwable) {
            }
        }

        return $removed;
    }

    /**
     * Remove a row found by listManagedRows()/readActual().
     *
     * @param  array<string, mixed>  $row
     */
    public function removeRow(Server $server, string $menu, array $row): void
    {
        $id = $row['.id'] ?? null;

        if ($id === null) {
            return;
        }

        try {
            $this->mikrotik->sendCommand($server, rtrim($menu, '/').'/remove', ['.id' => (string) $id]);
        } catch (Throwable $e) {
            Log::warning('tunneling: remove row failed', [
                'server_id' => $server->id,
                'menu' => $menu,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function findByMarker(Server $server, string $menu, string $marker): array
    {
        return array_values($this->mikrotik->queryRouter($server, $menu.'/print', ['comment' => $marker]));
    }

    /**
     * Attributes that differ between actual row and desired payload.
     * String-compares normalized values; create-only keys are skipped.
     *
     * @param  array<string, mixed>  $actual
     * @param  array<string, string>  $desired
     * @return array<string, string>
     */
    protected function diff(array $actual, array $desired): array
    {
        $changes = [];

        foreach ($desired as $key => $value) {
            if (in_array($key, self::CREATE_ONLY_KEYS, true)) {
                continue;
            }

            $current = $this->normalizeValue($actual[$key] ?? null);

            if ($current !== $value) {
                $changes[$key] = $value;
            }
        }

        return $changes;
    }

    /** Payload keys that are RouterOS flags (empty string must be sent). */
    private const FLAG_KEYS = [
        'fib',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function normalizePayload(array $payload): array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue;
            }

            if ($value === '' && ! in_array((string) $key, self::FLAG_KEYS, true)) {
                continue;
            }

            $normalized[(string) $key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    protected function normalizeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        $string = trim((string) $value);

        // RouterOS prints booleans as true/false on some menus.
        return match ($string) {
            'true' => 'yes',
            'false' => 'no',
            default => $string,
        };
    }

    protected function isTunnelInterfaceMenu(string $menu): bool
    {
        $menu = rtrim($menu, '/');

        return in_array($menu, self::TUNNEL_INTERFACE_MENUS, true);
    }

    /**
     * @param  array<string, string>  $payload
     */
    protected function addWithInterfaceRetry(
        Server $server,
        string $menu,
        array $payload,
        string $marker,
        ?string $name,
    ): void {
        try {
            $this->mikrotik->sendCommand($server, $menu.'/add', $payload + ['comment' => $marker]);
        } catch (Throwable $exception) {
            if ($name !== null
                && $name !== ''
                && $this->isTunnelInterfaceMenu($menu)
                && $this->isDuplicateInterfaceNameError($exception)
            ) {
                $this->removeConflictingTunnelInterface($server, $menu, $name, $marker);
                $this->mikrotik->sendCommand($server, $menu.'/add', $payload + ['comment' => $marker]);

                return;
            }

            throw $exception;
        }
    }

    protected function isDuplicateInterfaceNameError(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'already have interface with name')
            || str_contains($message, 'already exists');
    }

    /**
     * Drop stale tunnel interfaces that block add after kind switch (same name
     * or same marker on a different menu).
     */
    protected function removeConflictingTunnelInterface(
        Server $server,
        string $targetMenu,
        string $name,
        string $marker,
    ): void {
        $targetMenu = rtrim($targetMenu, '/');

        foreach (self::TUNNEL_INTERFACE_MENUS as $menu) {
            if ($menu === $targetMenu) {
                continue;
            }

            foreach ($this->findByMarker($server, $menu, $marker) as $row) {
                $this->removeRow($server, $menu, $row);
            }
        }

        foreach (self::TUNNEL_INTERFACE_MENUS as $menu) {
            try {
                $rows = $this->mikrotik->queryRouter($server, $menu.'/print', ['name' => $name]);
            } catch (Throwable) {
                continue;
            }

            foreach ($rows as $row) {
                if ($menu === $targetMenu && (string) ($row['comment'] ?? '') === $marker) {
                    continue;
                }

                $this->removeRow($server, $menu, $row);
            }
        }
    }
}
