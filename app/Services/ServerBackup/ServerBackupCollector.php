<?php

namespace App\Services\ServerBackup;

use App\Models\Server;

interface ServerBackupCollector
{
    public function supports(Server $server): bool;

    /**
     * @param  array{folder: string, relative: string, absolute: string, sections_dir: string}  $paths
     * @return array{
     *     format?: string,
     *     sections: array<string, mixed>,
     *     files?: list<array{name: string, label: string, file: string, bytes: int, type?: string}>,
     *     errors: list<array{section: string, message: string}>
     * }
     */
    public function collect(Server $server, array $paths): array;
}
