<?php

namespace App\Services\RouterOs;

use App\Models\DesiredNetworkObject;
use Illuminate\Support\Collection;

/**
 * Turns a server's pending DesiredNetworkObject rows into ONE RouterOS
 * script — one API round-trip (add + run + cleanup, via
 * MikrotikService::runEphemeralScript()) instead of the 2+ round-trips per
 * object that RouterCommandService::ensure() needs. This is what lets a
 * 30–40 object tunnel-group apply finish in a handful of seconds instead of
 * accumulating tens of seconds of network round-trip latency.
 *
 * Every command is wrapped in `:do {…} on-error={…}` so one failing line
 * (name clash, missing parent interface, etc.) never aborts the rest of the
 * script — DesiredStateApplier::applyForServerBatched() reconciles real
 * outcomes afterwards with a handful of grouped-by-menu `/print` calls
 * (RouterCommandService::listManagedRows()), not by trusting this script's
 * silence.
 *
 * Semantics mirror RouterCommandService::ensure() exactly:
 *   - singleton object_type -> blind `<menu> set <fields>` (no marker, no add/remove)
 *   - everything else       -> `<menu> add <fields> comment=<marker>`,
 *                              falling back to `<menu> set [find comment=<marker>] <fields>`
 *                              when add fails (row already exists)
 *   - removing status       -> `<menu> remove [find comment=<marker>]`
 */
class DesiredStateScriptBuilder
{
    /**
     * @param  Collection<int, DesiredNetworkObject>  $removing
     * @param  Collection<int, DesiredNetworkObject>  $applying
     */
    public function build(Collection $removing, Collection $applying): string
    {
        $lines = [];

        foreach ($removing as $object) {
            if ($object->object_type === 'singleton') {
                continue; // no identity to remove — a later apply will just `set` it again.
            }

            $menu = $this->menu($object);
            $marker = $this->escape($object->marker);
            $lines[] = ":do {{$menu} remove [find comment=\"{$marker}\"]} on-error={}";
        }

        foreach ($applying as $object) {
            $menu = $this->menu($object);
            $fields = $this->fields($object->payload ?? []);

            if ($object->object_type === 'singleton') {
                $lines[] = $fields === ''
                    ? ":do {{$menu} set} on-error={}"
                    : ":do {{$menu} set {$fields}} on-error={}";

                continue;
            }

            $marker = $this->escape($object->marker);
            $addFields = $fields === '' ? "comment=\"{$marker}\"" : "{$fields} comment=\"{$marker}\"";

            $lines[] = ':do {';
            $lines[] = "  {$menu} add {$addFields}";
            $lines[] = '} on-error={';
            $lines[] = "  :local rid [{$menu} find comment=\"{$marker}\"]";
            $lines[] = '  :if ([:len $rid] > 0) do={';
            $lines[] = $fields === ''
                ? "    {$menu} set \$rid"
                : "    {$menu} set \$rid {$fields}";
            $lines[] = '  }';
            $lines[] = '}';
        }

        return implode("\n", $lines);
    }

    protected function menu(DesiredNetworkObject $object): string
    {
        return rtrim($object->menu, '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function fields(array $payload): string
    {
        $parts = [];

        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalized = $this->normalizeValue($value);
            $key = (string) $key;

            // RouterOS boolean flags (e.g. `fib`) are written as a bare
            // keyword in script form, matching the empty-string-equals-flag
            // convention RouterCommandService::normalizePayload() uses for
            // the API protocol.
            $parts[] = $normalized === '' ? $key : "{$key}=\"{$this->escape($normalized)}\"";
        }

        return implode(' ', $parts);
    }

    protected function normalizeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        $string = trim((string) $value);

        return match ($string) {
            'true' => 'yes',
            'false' => 'no',
            default => $string,
        };
    }

    /**
     * Escapes backslashes, quotes and `$` (RouterOS script interpolates `$`
     * inside quoted strings too — user-supplied secrets/passwords must not
     * be allowed to break out of their string literal).
     */
    protected function escape(string $value): string
    {
        return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
    }
}
