<?php

namespace App\Services\ServerBackup;

use App\Models\Server;

class MikrotikServerBackupCollector implements ServerBackupCollector
{
    public function __construct(
        protected MikrotikNativeBackupService $nativeBackup,
    ) {}

    public function supports(Server $server): bool
    {
        return $server->isMikrotik();
    }

    public function collect(Server $server, array $paths): array
    {
        $filename = 'mikrotik-'.$paths['folder'].'.backup';
        $localPath = $paths['absolute'].DIRECTORY_SEPARATOR.$filename;

        $result = $this->nativeBackup->createAndDownload($server, $localPath);

        return [
            'format' => 'mikrotik_native',
            'sections' => [],
            'files' => [[
                'name' => 'native_backup',
                'label' => $result['filename'],
                'file' => $filename,
                'bytes' => $result['bytes'],
                'type' => 'mikrotik_backup',
            ]],
            'errors' => [],
        ];
    }
}
